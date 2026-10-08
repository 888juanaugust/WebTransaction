<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Domain\Posting\Contracts\Postable;
use App\Models\GeneralLedger\JournalEntry;
use App\Models\GeneralLedger\Posting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one way anything reaches the ledgers. post() supersedes the document's
 * active posting and writes a fresh one from what the document declares, in
 * one transaction; unpost() only supersedes. Readers of the ledgers filter on
 * active postings, so a superseded revision vanishes without a reversal row.
 */
final class PostingService
{
    /** @var list<callable(Posting, PostingBuilder): void> extra writers registered by later modules (stock, allocations) */
    private array $writers = [];

    /** @var list<callable(Posting): void> told when a posting is superseded, so caches depending on it refresh */
    private array $unposters = [];

    public function __construct(private readonly PeriodLock $periods) {}

    /** @param  callable(Posting, PostingBuilder): void  $writer */
    public function extend(callable $writer): void
    {
        $this->writers[] = $writer;
    }

    /** @param  callable(Posting): void  $unposter */
    public function onUnpost(callable $unposter): void
    {
        $this->unposters[] = $unposter;
    }

    public function post(Postable&Model $document, ?int $userId = null): Posting
    {
        $userId ??= auth()->id();

        return DB::transaction(function () use ($document, $userId): Posting {
            $this->periods->assertOpen($document->postingDate(), $document->postingNumber());

            $previous = $this->supersede($document->postingKey(), $userId);

            $builder = new PostingBuilder($document->postingBranchId(), Tags::of($document));
            $document->buildPostings($builder);
            $builder->assertBalanced();

            $posting = Posting::query()->create([
                'posting_key' => $document->postingKey(),
                'revision' => ($previous?->revision ?? 0) + 1,
                'document_type' => $document->getMorphClass(),
                'document_id' => $document->getKey(),
                'trans_date' => $document->postingDate()->toDateString(),
                'branch_id' => $document->postingBranchId(),
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            if ($builder->journalLines() !== []) {
                $entry = JournalEntry::query()->create([
                    'posting_id' => $posting->id,
                    'number' => $document->postingNumber(),
                    'trans_date' => $document->postingDate()->toDateString(),
                    'source_type' => $document->getMorphClass(),
                    'source_number' => $document->postingNumber(),
                    'description' => $document->postingDescription(),
                    'branch_id' => $document->postingBranchId(),
                ]);
                $rows = [];
                foreach ($builder->journalLines() as $i => $line) {
                    $rows[] = $line + [
                        'journal_entry_id' => $entry->id,
                        'posting_id' => $posting->id,
                        'sort' => $i,
                        'trans_date' => $document->postingDate()->toDateString(),
                    ];
                }
                $entry->lines()->createMany($rows);
            }

            foreach ($this->writers as $writer) {
                $writer($posting, $builder);
            }
            if ($previous !== null) {
                foreach ($this->unposters as $unposter) {
                    $unposter($previous);
                }
            }

            return $posting;
        });
    }

    /** Removes a document's effects from the ledgers by superseding its posting; the rows stay as history. */
    public function unpost(Postable&Model $document, ?int $userId = null): ?Posting
    {
        $userId ??= auth()->id();

        return DB::transaction(function () use ($document, $userId): ?Posting {
            $active = Posting::active()->where('posting_key', $document->postingKey())->first();
            if ($active !== null) {
                $this->periods->assertOpen($active->trans_date, $document->postingNumber());
            }

            $previous = $this->supersede($document->postingKey(), $userId);
            if ($previous !== null) {
                foreach ($this->unposters as $unposter) {
                    $unposter($previous);
                }
            }

            return $previous;
        });
    }

    public function activePosting(Postable $document): ?Posting
    {
        return Posting::active()->where('posting_key', $document->postingKey())->first();
    }

    private function supersede(string $key, ?int $userId): ?Posting
    {
        $active = Posting::active()->where('posting_key', $key)->lockForUpdate()->first();
        if ($active === null) {
            return null;
        }
        $active->update(['superseded_at' => now(), 'superseded_by' => $userId]);

        return $active;
    }
}
