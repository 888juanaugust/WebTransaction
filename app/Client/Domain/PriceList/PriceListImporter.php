<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListImportRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Parses an uploaded file into the import's rows and builds its diff. Runs
 * again from scratch whenever asked (the rows are replaced), so a queued
 * job that runs twice leaves the same result.
 */
final class PriceListImporter
{
    public function __construct(private readonly ImportDiff $diff) {}

    public function parseToStaging(PriceListImport $import, string $path): void
    {
        if (! in_array($import->status, [PriceListImport::UPLOADED, PriceListImport::PARSING, PriceListImport::PARSED, PriceListImport::FAILED], true)) {
            throw new RuntimeException(__('Import :name is :status and cannot be parsed again.', ['name' => $import->original_filename, 'status' => $import->status]));
        }
        $import->forceFill(['status' => PriceListImport::PARSING, 'parse_error' => null])->saveQuietly();

        try {
            DB::transaction(function () use ($import, $path): void {
                $import->rows()->delete();
                $parser = $import->isCanonical() ? new CanonicalFileParser : new SupplierWorkbookParser;
                $seen = [];
                $buffer = [];
                $blockers = 0;
                $notes = 0;
                $total = 0;
                foreach ($parser->parse($path) as $row) {
                    if ($row->kode !== null && isset($seen[$row->kode])) {
                        $row->blocker('kode_duplikat', __('KODE :kode appears more than once (first at row :row).', ['kode' => $row->kode, 'row' => $seen[$row->kode]]));
                    } elseif ($row->kode !== null) {
                        $seen[$row->kode] = $row->rowNumber;
                    }
                    $attributes = $row->toAttributes();
                    $blockers += $attributes['status'] === PriceListImportRow::BLOCKER ? 1 : 0;
                    $notes += $attributes['status'] === PriceListImportRow::NOTE ? 1 : 0;
                    $total++;
                    $attributes['raw'] = json_encode($attributes['raw'], JSON_UNESCAPED_UNICODE);
                    $attributes['issues'] = json_encode($attributes['issues'], JSON_UNESCAPED_UNICODE);
                    $buffer[] = $attributes + ['import_id' => $import->id, 'created_at' => now(), 'updated_at' => now()];
                    if (count($buffer) >= 500) {
                        PriceListImportRow::query()->insert($buffer);
                        $buffer = [];
                    }
                }
                if ($buffer !== []) {
                    PriceListImportRow::query()->insert($buffer);
                }
                $import->forceFill(['status' => PriceListImport::PARSED, 'row_count' => $total, 'blocker_count' => $blockers, 'note_count' => $notes, 'parse_error' => null])->saveQuietly();
            });
        } catch (Throwable $e) {
            $import->forceFill(['status' => PriceListImport::FAILED, 'parse_error' => $e->getMessage()])->saveQuietly();
            throw $e;
        }

        $this->diff->build($import);
    }
}
