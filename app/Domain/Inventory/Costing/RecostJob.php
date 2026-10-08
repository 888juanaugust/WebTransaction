<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Costing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Re-posts a long list of documents in date order, receipts first, as one batch of the recoster: the documents
 * those re-posts reach join the same pass instead of starting jobs of their own. One transaction: it all lands, or
 * the job fails whole (and is retried). Idempotent: posting a document that already reflects the current average
 * changes nothing but its revision number. One batch at a time across the workers: two batches re-posting the same
 * documents at once would each read the other's half-written averages.
 */
final class RecostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param  list<array{type: string, id: int, date: string, in: bool}>  $documents */
    public function __construct(public readonly array $documents) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('recost'))->releaseAfter(30)->expireAfter(3600)];
    }

    public function handle(Recoster $recoster): void
    {
        DB::transaction(fn () => $recoster->runBatch($this->documents));
    }
}
