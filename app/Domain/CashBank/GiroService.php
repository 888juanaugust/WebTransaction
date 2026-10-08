<?php

declare(strict_types=1);

namespace App\Domain\CashBank;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuRegistry;
use App\Domain\Audit\Auditor;
use App\Domain\CashBank\Contracts\GiroSource;
use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Posting\DocumentGuard;
use App\Domain\Posting\PostingService;
use App\Models\CashBank\Giro;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The giro register (K-06). A document settled by cheque registers a giro
 * when it is saved; the giro clears on the day the bank honours it, posting
 * the bank leg then; a bounced giro reverses its document's effects on the day
 * it bounced.
 */
final class GiroService
{
    public function __construct(private readonly PostingService $postings, private readonly HakAkses $akses, private readonly MenuRegistry $menus, private readonly DocumentGuard $guard) {}

    /**
     * Whether the signed-in user may record the bank's answer on this giro: the update right on its receipt's or
     * payment's screen, a branch they are assigned to, and the "edit other users' transactions" right when someone
     * else entered it; under segregation of duties, never the person who entered it. (A run without a user, from the
     * console, is the system's.)
     */
    public function allows(Giro $giro): bool
    {
        try {
            $this->assertAllowed($giro);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assertAllowed(Giro $giro, ?CarbonImmutable $on = null): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }
        $source = $giro->source;
        $menu = $source !== null ? $this->menus->menuKeyForModel($source::class) : null;
        if ($source === null || $menu === null || ! $this->akses->allows($user, $menu, Hak::Update)) {
            throw new RuntimeException(__('Recording a giro\'s clearing or bounce takes the update right on its document\'s screen.'));
        }
        if (! BranchLimit::allows($user, $source->getAttribute('branch_id') !== null ? (int) $source->getAttribute('branch_id') : null)) {
            throw new RuntimeException(__('You are not assigned to that branch.'));
        }
        $creator = $source->getAttribute('created_by');
        if ($creator !== null && (int) $creator === $user->id && BusinessRule::SegregationOfDuties->isOn()) {
            throw new RuntimeException(__('Segregation of duties: :number was entered by you; someone else records what the bank did with it.', ['number' => $giro->number]));
        }
        if ($creator !== null && (int) $creator !== $user->id && ! $this->akses->allowsSpecial($user, HakKhusus::EditOthersTransactions)) {
            throw new RuntimeException(__(':number was entered by another user; changing it takes the "edit other users\' transactions" right.', ['number' => $giro->number]));
        }
        if ($on !== null) {
            $this->guard->assertDateAllowed(null, $on);
        }
    }

    /** Keeps the register in step with a saved document; the document's own posting follows the giro's state. */
    public function sync(GiroSource&Model $document): ?Giro
    {
        $details = $document->giroDetails();
        $existing = $document->giro()->first();

        if ($details === null) {
            if ($existing !== null && $existing->isOutstanding()) {
                $existing->delete();
            }

            return null;
        }

        if ($existing !== null && ! $existing->isOutstanding()) {
            return $existing; // settled giros keep their record; the guard blocks the document anyway
        }

        $bankAccountId = (int) $document->getAttribute('bank_account_id');

        return $document->giro()->updateOrCreate([], [
            'direction' => $details->direction,
            'number' => $details->number,
            'bank_account_id' => $bankAccountId,
            'party_id' => $details->partyId,
            'party_name' => $details->partyName,
            'trans_date' => $document->getAttribute('trans_date'),
            'due_date' => $details->dueDate,
            'amount' => $details->amount,
            'status' => Giro::OUTSTANDING,
        ]);
    }

    /** The bank honoured the giro: it leaves giros receivable/payable for the bank account on that day. */
    public function clear(Giro $giro, CarbonImmutable|string $on, ?int $userId = null): void
    {
        if (! $giro->isOutstanding()) {
            throw new RuntimeException(__('Giro :number is :status; only an outstanding giro clears.', ['number' => $giro->number, 'status' => $giro->status]));
        }
        $on = CarbonImmutable::parse($on);
        $this->assertAllowed($giro, $on);
        if ($on->lt($giro->trans_date)) {
            throw new RuntimeException(__('Giro :number cannot clear before it was received.', ['number' => $giro->number]));
        }

        DB::transaction(function () use ($giro, $on, $userId): void {
            $giro->forceFill(['status' => Giro::CLEARED, 'settled_on' => $on, 'settled_by' => $userId ?? auth()->id()])->save();
            $this->postings->post($giro, $userId);
            Auditor::log('giro_cleared', $giro, $giro->number, ['on' => $on->toDateString(), 'amount' => $giro->amount], $on->toDateString());
        });
    }

    /** The bank refused the giro: what its receipt or payment did is reversed on the bounce date, so what it settled is open again. */
    public function bounce(Giro $giro, CarbonImmutable|string $on, ?string $reason = null, ?int $userId = null): void
    {
        if (! $giro->isOutstanding()) {
            throw new RuntimeException(__('Giro :number is :status; only an outstanding giro bounces.', ['number' => $giro->number, 'status' => $giro->status]));
        }
        $on = CarbonImmutable::parse($on);
        $this->assertAllowed($giro, $on);
        if ($on->lt($giro->trans_date)) {
            throw new RuntimeException(__('Giro :number cannot bounce before it was received.', ['number' => $giro->number]));
        }

        DB::transaction(function () use ($giro, $on, $reason, $userId): void {
            $giro->forceFill(['status' => Giro::BOUNCED, 'settled_on' => $on, 'settled_by' => $userId ?? auth()->id()])->save();
            $this->postings->post($giro, $userId); // the reversal, dated the bounce
            Auditor::log('giro_bounced', $giro, $giro->number, ['on' => $on->toDateString(), 'reason' => $reason, 'amount' => $giro->amount], $on->toDateString());
        });
    }
}
