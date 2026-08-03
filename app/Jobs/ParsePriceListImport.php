<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\PriceList\PriceListImporter;
use App\Models\PriceListImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Parse an uploaded price list into staging rows.
 *
 * The raw file is already stored — this job never touches it beyond reading.
 * Idempotent: parseToStaging() clears prior staging rows before writing, so a
 * retry replaces rather than duplicates.
 */
class ParsePriceListImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public readonly int $importId,
        public readonly bool $canonical = true,
    ) {}

    public function handle(PriceListImporter $importer): void
    {
        $import = PriceListImport::findOrFail($this->importId);

        try {
            $importer->parseToStaging(
                $import,
                Storage::disk('local')->path($import->stored_path),
                $this->canonical,
            );
        } catch (Throwable $e) {
            $import->forceFill([
                'status' => PriceListImport::STATUS_FAILED,
                'parse_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
