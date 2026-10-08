<?php

declare(strict_types=1);

namespace App\Domain\Posting\Blockers;

use App\Domain\CashBank\Contracts\GiroSource;
use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Shared\Format;
use Illuminate\Database\Eloquent\Model;

/** A document whose giro has cleared or bounced is history: the bank decided it. */
final class GiroBlocker implements Blocker
{
    public function blocks(Postable|Model $document): ?string
    {
        if (! $document instanceof GiroSource) {
            return null;
        }
        $giro = $document->giro()->first();
        if ($giro === null || $giro->isOutstanding()) {
            return null;
        }

        return "its giro {$giro->number} {$giro->status} on ".Format::date($giro->settled_on).'.';
    }
}
