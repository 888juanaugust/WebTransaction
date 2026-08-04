<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\PriceResolver;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The import pipeline:
 *
 *   upload → store raw file forever → parse to staging (queued job)
 *          → validate → diff preview → human approves → publish as new version
 *
 * Nothing here writes to live prices. Publishing inserts a *new* version;
 * a price is never UPDATEd.
 */
class PriceListImporter
{
    public function __construct(
        private readonly SupplierWorkbookParser $supplierParser,
        private readonly CanonicalFileParser $canonicalParser,
        private readonly AuditLogger $audit,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * Parse an uploaded file into staging rows. Called from a queued job.
     *
     * Idempotent: re-parsing an import clears its previous staging rows first,
     * so a retried job cannot double the row count.
     */
    public function parseToStaging(PriceListImport $import, string $absolutePath, bool $canonical = true): void
    {
        $import->forceFill(['status' => PriceListImport::STATUS_PARSING])->save();

        DB::transaction(function () use ($import, $absolutePath, $canonical) {
            $import->rows()->delete();

            $parser = $canonical ? $this->canonicalParser : $this->supplierParser;

            $seen = [];
            $buffer = [];
            $rowCount = 0;
            $blockers = 0;
            $notes = 0;

            foreach ($parser->parse($absolutePath) as $parsed) {
                // Duplicate KODE is a blocker: one row = one KODE, and we have
                // no way to know which of two prices the supplier meant.
                if ($parsed->kode !== null) {
                    if (isset($seen[$parsed->kode])) {
                        $parsed->blocker(
                            'kode_duplikat',
                            "KODE {$parsed->kode} muncul lebih dari sekali (baris {$seen[$parsed->kode]})."
                        );
                    } else {
                        $seen[$parsed->kode] = $parsed->sourceRowNumber;
                    }
                }

                $attributes = $parsed->toRowAttributes();
                $attributes['import_id'] = $import->id;
                $attributes['raw'] = json_encode($attributes['raw']);
                $attributes['issues'] = $attributes['issues'] === null
                    ? null
                    : json_encode($attributes['issues']);
                $attributes['created_at'] = now();
                $attributes['updated_at'] = now();

                $buffer[] = $attributes;
                $rowCount++;

                match ($attributes['status']) {
                    PriceListImportRow::STATUS_BLOCKER => $blockers++,
                    PriceListImportRow::STATUS_NOTE => $notes++,
                    default => null,
                };

                if (count($buffer) >= 500) {
                    PriceListImportRow::insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                PriceListImportRow::insert($buffer);
            }

            $import->forceFill([
                'status' => PriceListImport::STATUS_PARSED,
                'row_count' => $rowCount,
                'blocker_count' => $blockers,
                'note_count' => $notes,
                'parse_error' => null,
            ])->save();
        });

        $this->buildDiff($import);
    }

    /**
     * Compare staged rows against the currently effective version and bucket
     * every SKU. Stores the summary on the import for the preview screen.
     */
    public function buildDiff(PriceListImport $import): ImportDiff
    {
        $current = PriceListVersion::effectiveOn(now());

        $currentPrices = $current === null
            ? []
            : PriceListItem::query()
                ->where('version_id', $current->id)
                ->pluck('harga', 'kode')
                ->all();

        $newSkus = 0;
        $changed = 0;
        $unchanged = 0;
        $errors = 0;
        $moves = [];
        $seenKodes = [];
        $biggestSingleMoveBps = 0;

        $import->rows()->orderBy('id')->chunkById(500, function ($rows) use (
            &$newSkus, &$changed, &$unchanged, &$errors, &$moves, &$seenKodes, &$biggestSingleMoveBps,
            $currentPrices
        ) {
            foreach ($rows as $row) {
                if ($row->status === PriceListImportRow::STATUS_BLOCKER) {
                    $errors++;
                    $row->forceFill(['diff_bucket' => PriceListImportRow::BUCKET_ERROR])->save();

                    continue;
                }

                $seenKodes[$row->kode] = true;
                $old = $currentPrices[$row->kode] ?? null;

                if ($old === null) {
                    $newSkus++;
                    $row->forceFill(['diff_bucket' => PriceListImportRow::BUCKET_NEW])->save();

                    continue;
                }

                if ($old === $row->harga) {
                    $unchanged++;
                    $row->forceFill([
                        'diff_bucket' => PriceListImportRow::BUCKET_UNCHANGED,
                        'harga_lama' => $old,
                    ])->save();

                    continue;
                }

                $changed++;
                $deltaBps = $old === 0 ? 10_000 : (int) round(abs($row->harga - $old) * 10_000 / $old);
                $biggestSingleMoveBps = max($biggestSingleMoveBps, $deltaBps);

                $row->forceFill([
                    'diff_bucket' => PriceListImportRow::BUCKET_CHANGED,
                    'harga_lama' => $old,
                ])->save();

                $moves[] = [
                    'kode' => $row->kode,
                    'harga_lama' => $old,
                    'harga_baru' => $row->harga,
                    'delta_bps' => ($row->harga > $old ? 1 : -1) * $deltaBps,
                ];
            }
        });

        $missing = 0;

        foreach (array_keys($currentPrices) as $kode) {
            if (! isset($seenKodes[$kode])) {
                $missing++;
            }
        }

        usort($moves, fn ($a, $b) => abs($b['delta_bps']) <=> abs($a['delta_bps']));

        $diff = $this->applyBrake(
            newSkus: $newSkus,
            changed: $changed,
            unchanged: $unchanged,
            missing: $missing,
            errors: $errors,
            moves: array_slice($moves, 0, 25),
            biggestSingleMoveBps: $biggestSingleMoveBps,
            isFullReplacement: $import->is_full_replacement,
        );

        $import->forceFill(['diff' => $diff->toArray()])->save();

        return $diff;
    }

    /**
     * Safety brake: if more than 20% of prices change, or any single price
     * moves more than 50%, the import needs a second confirmation that names
     * the numbers.
     *
     * @param  list<array{kode: string, harga_lama: int, harga_baru: int, delta_bps: int}>  $moves
     */
    private function applyBrake(
        int $newSkus,
        int $changed,
        int $unchanged,
        int $missing,
        int $errors,
        array $moves,
        int $biggestSingleMoveBps,
        bool $isFullReplacement,
    ): ImportDiff {
        $comparable = $changed + $unchanged;
        $changedShareBps = $comparable === 0 ? 0 : (int) round($changed * 10_000 / $comparable);

        $maxShare = (int) config('penjualan.import_brake.max_changed_share_bps');
        $maxMove = (int) config('penjualan.import_brake.max_single_move_bps');

        $reasons = [];

        if ($changedShareBps > $maxShare) {
            $reasons[] = sprintf(
                '%d dari %d harga berubah (%.1f%%, ambang %.0f%%).',
                $changed,
                $comparable,
                $changedShareBps / 100,
                $maxShare / 100,
            );
        }

        if ($biggestSingleMoveBps > $maxMove && $moves !== []) {
            $worst = $moves[0];
            $reasons[] = sprintf(
                'Perubahan terbesar %s: %s → %s (%+.1f%%).',
                $worst['kode'],
                number_format($worst['harga_lama'], 0, ',', '.'),
                number_format($worst['harga_baru'], 0, ',', '.'),
                $worst['delta_bps'] / 100,
            );
        }

        return new ImportDiff(
            newSkus: $newSkus,
            priceChanged: $changed,
            unchanged: $unchanged,
            missingFromFile: $missing,
            errors: $errors,
            biggestMoves: $moves,
            brakeTripped: $reasons !== [],
            brakeReasons: $reasons,
            isFullReplacement: $isFullReplacement,
        );
    }

    /**
     * Publish the staged rows as a new price list version.
     *
     * Blocker rows are never published. SKUs missing from the file are left
     * alone unless the operator explicitly ticked "this file is a full
     * replacement" — silently deactivating a SKU because a supplier forgot a
     * sheet is how a live product disappears from the catalogue.
     *
     * @throws DomainException when the brake is tripped and unacknowledged
     */
    public function publish(
        PriceListImport $import,
        User $approver,
        \DateTimeInterface $effectiveFrom,
        ?string $brakeAcknowledgement = null,
        ?string $note = null,
    ): PriceListVersion {
        if ($import->status !== PriceListImport::STATUS_PARSED) {
            throw new DomainException("Import berstatus {$import->status}, belum siap dipublikasikan.");
        }

        $diff = $import->diff ?? [];

        if (($diff['brake_tripped'] ?? false) && blank($brakeAcknowledgement)) {
            throw new DomainException(
                'Perubahan besar terdeteksi dan butuh konfirmasi kedua: '
                .implode(' ', $diff['brake_reasons'] ?? [])
            );
        }

        return DB::transaction(function () use ($import, $approver, $effectiveFrom, $brakeAcknowledgement, $note, $diff) {
            $version = PriceListVersion::create([
                'effective_from' => $effectiveFrom,
                'source_file_path' => $import->stored_path,
                'note' => $note ?? $import->note,
                'status' => PriceListVersion::STATUS_DRAFT,
            ]);

            $publishable = $import->rows()
                ->where('status', '!=', PriceListImportRow::STATUS_BLOCKER)
                ->orderBy('id');

            $buffer = [];
            $published = 0;

            $publishable->chunkById(500, function ($rows) use ($version, &$buffer, &$published) {
                foreach ($rows as $row) {
                    $this->upsertProduct($row);

                    $buffer[] = [
                        'version_id' => $version->id,
                        'kode' => $row->kode,
                        'harga' => $row->harga,
                        'qty_per_ctn' => $row->qty_per_ctn ?? 1,
                        'aktif' => $row->aktif,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $published++;

                    if (count($buffer) >= 500) {
                        PriceListItem::insert($buffer);
                        $buffer = [];
                    }
                }
            });

            if ($buffer !== []) {
                PriceListItem::insert($buffer);
            }

            $carried = $this->carryForwardMissing($import, $version);

            $previous = PriceListVersion::query()
                ->where('status', PriceListVersion::STATUS_PUBLISHED)
                ->get();

            foreach ($previous as $old) {
                $old->forceFill(['status' => PriceListVersion::STATUS_SUPERSEDED])->save();
            }

            $version->forceFill([
                'status' => PriceListVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $approver->id,
            ])->save();

            $import->forceFill([
                'status' => PriceListImport::STATUS_PUBLISHED,
                'price_list_version_id' => $version->id,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'brake_acknowledgement' => $brakeAcknowledgement,
            ])->save();

            $this->audit->log(
                action: 'price_list_published',
                subject: $version,
                newValue: [
                    'version_id' => $version->id,
                    'effective_from' => $version->effective_from->toDateString(),
                    'items_from_file' => $published,
                    'items_carried_forward' => $carried,
                    'diff' => $diff,
                    'brake_acknowledgement' => $brakeAcknowledgement,
                ],
                actor: $approver,
            );

            // The resolver caches the effective version and the rows under it
            // for the life of the request. Publishing is the one thing that
            // invalidates that, so tell it before anything prices again.
            $this->prices->forget();

            return $version;
        });
    }

    /**
     * Copy SKUs that were in the previous version but not in this file.
     *
     * Default is leave-alone: they keep their price and stay active. Only a
     * full-replacement import deactivates them, and even then the row is
     * carried forward rather than dropped, so history stays intact.
     */
    private function carryForwardMissing(PriceListImport $import, PriceListVersion $version): int
    {
        $previous = PriceListVersion::query()
            ->published()
            ->where('id', '!=', $version->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return 0;
        }

        $inFile = $import->rows()
            ->where('status', '!=', PriceListImportRow::STATUS_BLOCKER)
            ->pluck('kode')
            ->filter()
            ->all();

        $carried = 0;
        $buffer = [];

        PriceListItem::query()
            ->where('version_id', $previous->id)
            ->whereNotIn('kode', $inFile === [] ? [''] : $inFile)
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($version, $import, &$buffer, &$carried) {
                foreach ($items as $item) {
                    $buffer[] = [
                        'version_id' => $version->id,
                        'kode' => $item->kode,
                        'harga' => $item->harga,
                        'qty_per_ctn' => $item->qty_per_ctn,
                        'aktif' => $import->is_full_replacement ? false : $item->aktif,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $carried++;

                    if (count($buffer) >= 500) {
                        PriceListItem::insert($buffer);
                        $buffer = [];
                    }
                }
            });

        if ($buffer !== []) {
            PriceListItem::insert($buffer);
        }

        return $carried;
    }

    /**
     * Keep the product catalogue in step with the price list.
     *
     * Descriptive fields follow the file; `aktif` does not, because
     * deactivation is governed by the full-replacement rule above.
     */
    private function upsertProduct(PriceListImportRow $row): void
    {
        Product::query()->updateOrCreate(
            ['kode' => $row->kode],
            array_filter([
                'merk' => $row->merk,
                'kategori' => $row->kategori,
                'tipe_produk' => $row->tipe_produk,
                'mobil' => $row->mobil,
                'part_number' => $row->part_number,
                'description' => $row->description,
                'qty_per_ctn' => $row->qty_per_ctn ?? 1,
                'satuan_dasar' => $row->satuan_dasar ?? 'PCS',
                'catatan' => $row->catatan,
            ], fn ($v) => $v !== null),
        );
    }
}
