<?php

declare(strict_types=1);

namespace App\Client\Jobs;

use App\Client\Domain\PriceList\PriceListImporter;
use App\Client\Models\PriceListImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Parses an uploaded price list in the background. Running twice parses twice to the same rows. */
class ParsePriceListImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly int $importId) {}

    public function handle(PriceListImporter $importer): void
    {
        $import = PriceListImport::query()->find($this->importId);
        if ($import === null || in_array($import->status, [PriceListImport::PUBLISHED, PriceListImport::DISCARDED], true)) {
            return;
        }
        $importer->parseToStaging($import, Storage::disk('local')->path($import->stored_path));
    }
}
