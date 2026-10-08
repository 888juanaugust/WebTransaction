<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\Auditor;
use App\Domain\CashBank\Contracts\GiroSource;
use App\Domain\CashBank\GiroService;
use App\Domain\Fulfilment\FulfilmentService;
use App\Domain\Posting\Contracts\AppliesEffects;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Shared\RecordInUse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The write path of every document: guard, save, post (when the document
 * posts), refresh the documents it pulled from, keep its approval request,
 * record the revision and the audit entry, all in one transaction. The Filament document pages call these
 * hooks around their own saving.
 */
final class DocumentRepository
{
    public function __construct(
        private readonly PostingService $postings,
        private readonly DocumentGuard $guard,
        private readonly Revisions $revisions,
        private readonly FulfilmentService $fulfilment,
        private readonly ApprovalEngine $approvals,
    ) {}

    /** After a new document and its lines are in the database. */
    public function created(Model $document): void
    {
        DB::transaction(function () use ($document): void {
            $document->refresh();
            $this->guard->assertDateAllowed(null, $this->dateOf($document));
            $this->guard->assertBranchAllowed($this->branchOf($document));
            $this->assertSourcesApproved($this->fulfilment->sourcesOf($document));
            $this->syncGiro($document);
            if ($document instanceof Postable) {
                $this->postings->post($document);
            }
            if ($document instanceof AppliesEffects) {
                $document->applyEffects();
            }
            $this->fulfilment->refreshUpstream($document);
            $this->approvals->sync($document);
            $this->revisions->record($document, 'created', null, $this->snapshot($document));
            Auditor::log('created', $document, $this->number($document), [], $this->date($document));
        });
    }

    /**
     * Before an existing document is changed: the snapshot to compare against, or an exception.
     * The new branch is checked only when the edit names one ($branchGiven).
     */
    public function beforeUpdate(Model $document, ?CarbonInterface $newDate = null, ?int $newBranchId = null, bool $branchGiven = false): array
    {
        $this->guard->assertBranchAllowed($this->branchOf($document));
        if ($branchGiven) {
            $this->guard->assertBranchAllowed($newBranchId);
        }
        $this->guard->assertDateAllowed($this->dateOf($document), $newDate);
        if ($document instanceof Postable) {
            $this->guard->assertMutable($document, $newDate);
        } else {
            $this->guard->assertOwnOrAllowed($document, $this->number($document)); // an order or quotation too
        }

        return $this->snapshot($document) + ['sources' => $this->fulfilment->sourcesOf($document)];
    }

    /** After the header and lines are saved: re-post and record what changed. */
    public function updated(Model $document, array $before): void
    {
        DB::transaction(function () use ($document, $before): void {
            $document->refresh();
            $this->assertSourcesApproved(array_diff_key($this->fulfilment->sourcesOf($document), $before['sources'] ?? []));
            $this->syncGiro($document);
            if ($document instanceof Postable) {
                $this->postings->post($document);
            }
            if ($document instanceof AppliesEffects) {
                $document->applyEffects();
            }
            $this->fulfilment->refreshUpstream($document, $before['sources'] ?? []);
            $this->approvals->sync($document);
            $after = $this->snapshot($document);
            unset($before['sources']);
            $this->revisions->record($document, 'updated', $before, $after);
            Auditor::log('updated', $document, $this->number($document), ['before' => $before['header'] ?? null, 'after' => $after['header'] ?? null], $this->date($document));
        });
    }

    public function delete(Model $document): void
    {
        RecordInUse::guard($document, function () use ($document): void {
            $this->guard->assertBranchAllowed($this->branchOf($document));
            if ($document instanceof Postable) {
                $this->guard->assertDeletable($document, $this->postings->activePosting($document) !== null);
                $this->guard->assertMutable($document);
            } else {
                $this->guard->assertOwnOrAllowed($document, $this->number($document));
                $this->guard->assertPeriodOpen($this->date($document), $this->number($document));
                (new Blockers\ReferencedBlocker)->blocks($document) && throw new Exceptions\DocumentLockedException(__(':number cannot be deleted: another document has been made from it.', ['number' => $this->number($document)]));
            }
            $before = $this->snapshot($document);
            $sources = $this->fulfilment->sourcesOf($document);
            if ($document instanceof Postable) {
                $this->postings->unpost($document);
            }
            if ($document instanceof GiroSource) {
                $document->giro()->where('status', 'outstanding')->delete();
            }
            if ($document instanceof AppliesEffects) {
                $document->revertEffects();
            }
            $this->approvals->forget($document);
            $this->revisions->record($document, 'deleted', $before, null);
            Auditor::log('deleted', $document, $this->number($document), ['before' => $before['header'] ?? null], $this->date($document));
            $document->delete();
            $this->fulfilment->refreshUpstream($document, $sources);
        });
    }

    /**
     * Nothing is made from a document still waiting for approval, or rejected.
     *
     * @param  array<string, array{0: string, 1: int}>  $sources  source line type and id
     */
    private function assertSourcesApproved(array $sources): void
    {
        $checked = [];
        foreach ($sources as [$type, $id]) {
            $class = Relation::getMorphedModel((string) $type); // a mapped alias only, never a class name from a form
            $line = $class !== null ? $class::query()->find($id) : null;
            $parent = $line !== null && method_exists($line, 'document') ? $line->document() : null;
            if ($parent === null || isset($checked[$parent::class.':'.$parent->getKey()])) {
                continue;
            }
            $checked[$parent::class.':'.$parent->getKey()] = true;
            if (! $this->approvals->isApproved($parent)) {
                throw new Exceptions\DocumentLockedException(__(':number is not approved; nothing can be made from it yet.', ['number' => (string) $parent->getAttribute('number')]));
            }
        }
    }

    /** A receipt or payment by cheque registers its giro before it posts, so the posting follows the giro's state. */
    private function syncGiro(Model $document): void
    {
        if ($document instanceof GiroSource) {
            app(GiroService::class)->sync($document);
            $document->unsetRelation('giro');
        }
    }

    public function lockReason(Model $document): ?string
    {
        return $document instanceof Postable ? $this->guard->lockReason($document) : (new Blockers\ReferencedBlocker)->blocks($document);
    }

    private function snapshot(Model $document): array
    {
        if ($document instanceof Postable) {
            return $document->snapshot();
        }
        $header = collect($document->getAttributes())->except(['updated_at', 'created_at'])->all();
        $lines = method_exists($document, 'lines') ? $document->lines()->get()->map(fn ($l) => $l->getAttributes())->all() : [];

        return ['header' => $header, 'lines' => $lines];
    }

    private function number(Model $document): string
    {
        return $document instanceof Postable ? $document->postingNumber() : (string) $document->getAttribute('number');
    }

    private function dateOf(Model $document): ?CarbonInterface
    {
        $date = $document->getAttribute('trans_date');

        return $date ? Carbon::parse($date) : null;
    }

    private function branchOf(Model $document): ?int
    {
        $branchId = $document->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }

    private function date(Model $document): ?string
    {
        return $document->getAttribute('trans_date') ? Carbon::parse($document->getAttribute('trans_date'))->toDateString() : null;
    }
}
