<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Visits\StoreVisits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The 2-month photo retention, enforced nightly.
 *
 * Idempotent by construction: the purge selects only rows whose photo is
 * still on disk and unstamped, so a job that runs twice deletes nothing
 * twice. Deliberately daily rather than monthly — a nightly trickle of a
 * few files, instead of one night that deletes two months of photos in a
 * batch nobody can interrupt.
 */
class PurgeVisitPhotos implements ShouldQueue
{
    use Queueable;

    public function handle(StoreVisits $visits): void
    {
        $visits->purgeExpiredPhotos();
    }
}
