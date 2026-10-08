<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Company\DataStart;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\PostingService;
use App\Models\Company\OpeningBalance;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Posts the customer and vendor opening balances that were entered before
 * they became documents of their own, each on the data start date. Rows that
 * already carry a posting are left alone, so it can run any number of times;
 * run it again after moving the data start date.
 */
class PostOpeningBalancesCommand extends Command
{
    protected $signature = 'erp:post-opening-balances';

    protected $description = 'Post customer and vendor opening balances that carry no posting yet, on the data start date';

    public function handle(DocumentRepository $documents, PostingService $postings): int
    {
        $start = DataStart::openingDate();
        $posted = 0;
        $moved = 0;
        foreach (OpeningBalance::query()->orderBy('id')->get() as $opening) {
            $active = $postings->activePosting($opening);
            try {
                if ($active === null) {
                    $opening->forceFill(['trans_date' => $start, 'document_date' => $opening->document_date ?? $opening->trans_date])->saveQuietly();
                    $documents->created($opening->fresh());
                    $posted++;
                } elseif ($opening->trans_date->toDateString() !== $start) {
                    $before = $documents->beforeUpdate($opening);
                    $opening->forceFill(['trans_date' => $start])->save();
                    $documents->updated($opening, $before);
                    $moved++;
                }
            } catch (RuntimeException $e) {
                $this->warn("{$opening->postingNumber()}: {$e->getMessage()}");
            }
        }
        $this->info("Opening balances posted: {$posted}; moved to the data start date: {$moved}.");

        return self::SUCCESS;
    }
}
