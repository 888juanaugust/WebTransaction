<?php

declare(strict_types=1);

namespace App\Domain\Posting\Blockers;

use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Settlement\SettlementService;
use Illuminate\Database\Eloquent\Model;

/** A document with payments applied to it cannot change or go until the payments are undone. */
final class SettledBlocker implements Blocker
{
    public function __construct(private readonly SettlementService $settlement) {}

    public function blocks(Postable|Model $document): ?string
    {
        if (! $document instanceof Model || ! $document->getConnection()->getSchemaBuilder()->hasColumn($document->getTable(), 'paid_amount')) {
            return null;
        }

        return $this->settlement->hasAllocations($document) ? 'payments have been applied to it; undo them first.' : null;
    }
}
