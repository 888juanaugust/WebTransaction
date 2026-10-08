<?php

declare(strict_types=1);

namespace App\Domain\Posting\Blockers;

use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Shared\Format;
use App\Models\CashBank\BankReconciliationItem;
use App\Models\GeneralLedger\Posting;
use Illuminate\Database\Eloquent\Model;

/** A document whose bank line was reconciled cannot change or go: the bank agreed with it. */
final class ReconciledBlocker implements Blocker
{
    public function blocks(Postable|Model $document): ?string
    {
        if (! $document instanceof Postable) {
            return null;
        }
        $posting = Posting::active()->where('posting_key', $document->postingKey())->first();
        if ($posting === null) {
            return null;
        }
        $item = BankReconciliationItem::query()
            ->whereHas('journalLine', fn ($q) => $q->where('posting_id', $posting->id))
            ->first();

        return $item === null ? null : 'its bank line was reconciled on '.Format::date($item->cleared_on).'.';
    }
}
