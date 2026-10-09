<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListImportRow;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Domain\Shared\Format;
use App\Models\Inventory\Item;

/**
 * What an upload would change against the list in force today: every row in
 * one of five buckets, the share of prices that move, the biggest moves,
 * and whether the safety brake trips (more than a fifth of the prices
 * change, or one price moves by more than half).
 */
final class ImportDiff
{
    /** Buckets every parsed row, writes each row's bucket and old price, and stores the summary on the import. */
    public function build(PriceListImport $import): array
    {
        $old = $this->currentPrices();
        $counts = [PriceListImportRow::NEW => 0, PriceListImportRow::CHANGED => 0, PriceListImportRow::UNCHANGED => 0, PriceListImportRow::MISSING => 0, PriceListImportRow::ERROR => 0];
        $moves = [];
        $seen = [];
        $biggest = 0;

        $import->rows()->orderBy('id')->chunkById(500, function ($rows) use (&$counts, &$moves, &$seen, &$biggest, $old): void {
            foreach ($rows as $row) {
                if ($row->status === PriceListImportRow::BLOCKER || $row->kode === null) {
                    $counts[PriceListImportRow::ERROR]++;
                    $row->forceFill(['diff_bucket' => PriceListImportRow::ERROR])->saveQuietly();

                    continue;
                }
                $seen[$row->kode] = true;
                if (! array_key_exists($row->kode, $old)) {
                    $counts[PriceListImportRow::NEW]++;
                    $row->forceFill(['diff_bucket' => PriceListImportRow::NEW, 'harga_lama' => null])->saveQuietly();

                    continue;
                }
                $before = (int) $old[$row->kode];
                if ($before === (int) $row->harga) {
                    $counts[PriceListImportRow::UNCHANGED]++;
                    $row->forceFill(['diff_bucket' => PriceListImportRow::UNCHANGED, 'harga_lama' => $before])->saveQuietly();

                    continue;
                }
                $counts[PriceListImportRow::CHANGED]++;
                $row->forceFill(['diff_bucket' => PriceListImportRow::CHANGED, 'harga_lama' => $before])->saveQuietly();
                $deltaBps = $before === 0 ? 10_000 : (int) round(abs((int) $row->harga - $before) * 10_000 / $before);
                $moves[] = ['kode' => $row->kode, 'harga_lama' => $before, 'harga_baru' => (int) $row->harga, 'delta_bps' => ((int) $row->harga >= $before ? 1 : -1) * $deltaBps];
                $biggest = max($biggest, $deltaBps);
            }
        });

        $counts[PriceListImportRow::MISSING] = count(array_diff_key($old, $seen));
        usort($moves, fn (array $a, array $b) => abs($b['delta_bps']) <=> abs($a['delta_bps']));
        $moves = array_slice($moves, 0, 25);

        $comparable = $counts[PriceListImportRow::CHANGED] + $counts[PriceListImportRow::UNCHANGED];
        $changedShareBps = $comparable === 0 ? 0 : (int) round($counts[PriceListImportRow::CHANGED] * 10_000 / $comparable);
        $maxShare = (int) config('pricelist.brake.max_changed_share_bps', 2_000);
        $maxMove = (int) config('pricelist.brake.max_single_move_bps', 5_000);
        $reasons = [];
        if ($changedShareBps > $maxShare) {
            $reasons[] = __(':changed of :comparable prices change (:share%, the limit is :limit%).', ['changed' => $counts[PriceListImportRow::CHANGED], 'comparable' => $comparable, 'share' => number_format($changedShareBps / 100, 1), 'limit' => number_format($maxShare / 100, 0)]);
        }
        if ($biggest > $maxMove && $moves !== []) {
            $top = $moves[0];
            $reasons[] = __('The biggest move is :kode: :old → :new (:delta%).', ['kode' => $top['kode'], 'old' => Format::number($top['harga_lama']), 'new' => Format::number($top['harga_baru']), 'delta' => ($top['delta_bps'] >= 0 ? '+' : '').number_format($top['delta_bps'] / 100, 1)]);
        }

        $diff = [
            'buckets' => $counts,
            'changed_share_bps' => $changedShareBps,
            'biggest_moves' => $moves,
            'brake_tripped' => $reasons !== [],
            'brake_reasons' => $reasons,
            'is_full_replacement' => (bool) $import->is_full_replacement,
            'compared_to_version_id' => PriceListVersion::current()?->id,
        ];
        $import->forceFill(['diff' => $diff])->saveQuietly();

        return $diff;
    }

    /** @return array<string, int> item number → price per base unit in the version in force today */
    private function currentPrices(): array
    {
        $version = PriceListVersion::current();
        if ($version === null) {
            return [];
        }

        return PriceListItem::query()->where('version_id', $version->id)
            ->join('items', 'items.id', '=', 'price_list_items.item_id')
            ->pluck('price_list_items.price', 'items.number')
            ->map(fn ($p) => (int) $p)
            ->all();
    }

    /** One line a list can show. */
    public static function summary(?array $diff): string
    {
        if ($diff === null) {
            return __('Not processed yet.');
        }
        $b = $diff['buckets'] ?? [];

        return __('new :new · changed :changed · unchanged :unchanged · not in file :missing · errors :errors', [
            'new' => $b[PriceListImportRow::NEW] ?? 0, 'changed' => $b[PriceListImportRow::CHANGED] ?? 0, 'unchanged' => $b[PriceListImportRow::UNCHANGED] ?? 0,
            'missing' => $b[PriceListImportRow::MISSING] ?? 0, 'errors' => $b[PriceListImportRow::ERROR] ?? 0,
        ]);
    }

    /** Whether the item exists already, by number, for the view's "new SKU" hint. */
    public static function itemExists(string $kode): bool
    {
        return Item::query()->where('number', $kode)->exists();
    }
}
