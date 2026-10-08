<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\FixedAssets\FiscalDepreciator;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Builder;

/** Cash & bank mutations (R-15) and the depreciation schedule (R-16). */
final class CashAndAssetReports
{
    /** Opening, in, out and closing per cash/bank account over the period. @return list<array<string, mixed>> */
    public static function bankMutations(Period $period): array
    {
        $opening = Ledger::openingNet($period);
        $movement = Ledger::movement($period);
        $rows = [];
        $sum = ['opening' => 0, 'in' => 0, 'out' => 0, 'closing' => 0];
        foreach (Account::query()->ofType(AccountType::CashBank)->whereNull('parent_id')->orderBy('no')->get() as $account) {
            $open = $opening[$account->id] ?? 0;
            $in = $movement['debit'][$account->id] ?? 0;
            $out = $movement['credit'][$account->id] ?? 0;
            if ($open === 0 && $in === 0 && $out === 0) {
                continue;
            }
            $rows[] = ['id' => $account->id, 'no' => $account->no, 'name' => $account->name, 'opening' => $open, 'in' => $in, 'out' => $out, 'closing' => $open + $in - $out];
            $sum['opening'] += $open;
            $sum['in'] += $in;
            $sum['out'] += $out;
            $sum['closing'] += $open + $in - $out;
        }
        $rows[] = ['id' => 'total', 'no' => '', 'name' => 'Total'] + $sum + ['is_total' => true];

        return $rows;
    }

    /** Every asset with cost, the period's depreciation, accumulated and book value at the period's end. @return list<array<string, mixed>> */
    public static function depreciationSchedule(Period $period, ?int $categoryId = null, string $book = 'commercial'): array
    {
        if ($book === 'fiscal') {
            return self::fiscalSchedule($period, $categoryId);
        }
        $assets = FixedAsset::query()->with(['category', 'depreciations', 'disposals'])
            ->when($categoryId, fn (Builder $q) => $q->where('asset_category_id', $categoryId))
            ->where('trans_date', '<=', $period->untilDate())
            ->orderBy('number')
            ->get();
        $rows = [];
        $sum = ['cost' => 0, 'period' => 0, 'accumulated' => 0, 'book_value' => 0];
        foreach ($assets as $asset) {
            $upTo = $asset->depreciations->filter(fn ($d) => $d->trans_date->lte($period->until));
            $inPeriod = $upTo->filter(fn ($d) => $d->trans_date->gte($period->from));
            $accumulated = (int) $upTo->sum('amount') - (int) $asset->disposals->filter(fn ($d) => $d->trans_date->lte($period->until))->sum('depreciation_removed');
            $cost = (int) $asset->cost - (int) $asset->disposals->filter(fn ($d) => $d->trans_date->lte($period->until))->sum('cost_removed');
            $rows[] = [
                'id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'category' => $asset->category?->name, 'usage_date' => $asset->usage_date,
                'method' => $asset->depreciation_method->getLabel(), 'life' => $asset->useful_life_months, 'cost' => $cost, 'period' => (int) $inPeriod->sum('amount'),
                'accumulated' => $accumulated, 'book_value' => $cost - $accumulated, 'status' => $asset->status,
            ];
            $sum['cost'] += $cost;
            $sum['period'] += (int) $inPeriod->sum('amount');
            $sum['accumulated'] += $accumulated;
            $sum['book_value'] += $cost - $accumulated;
        }
        $rows[] = ['id' => 'total', 'number' => 'Total', 'name' => '', 'category' => '', 'usage_date' => null, 'method' => '', 'life' => ''] + $sum + ['status' => '', 'is_total' => true];

        return $rows;
    }

    /** The tax books: each fiscal asset's depreciation by its fiscal group (FiscalDepreciator), nothing posted. */
    private static function fiscalSchedule(Period $period, ?int $categoryId): array
    {
        $assets = FixedAsset::query()->with(['category', 'fiscalCategory'])
            ->where('fiscal', true)
            ->when($categoryId, fn (Builder $q) => $q->where('asset_category_id', $categoryId))
            ->where('trans_date', '<=', $period->untilDate())
            ->orderBy('number')
            ->get();
        $from = $period->from->format('Y-m');
        $until = $period->until->format('Y-m');
        $rows = [];
        $sum = ['cost' => 0, 'period' => 0, 'accumulated' => 0, 'book_value' => 0];
        foreach ($assets as $asset) {
            $months = FiscalDepreciator::months($asset);
            $inPeriod = array_sum(array_filter($months, fn ($m) => $m >= $from && $m <= $until, ARRAY_FILTER_USE_KEY));
            $accumulated = array_sum(array_filter($months, fn ($m) => $m <= $until, ARRAY_FILTER_USE_KEY));
            $cost = (int) $asset->cost;
            $group = $asset->fiscalCategory;
            $rows[] = [
                'id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'category' => $group?->name ?? $asset->category?->name, 'usage_date' => $asset->usage_date,
                'method' => $group ? FiscalDepreciator::methodOf($group)->getLabel().' '.Format::percent($group->rate_percent) : '—',
                'life' => $group ? (int) $group->useful_life_years * 12 : '', 'cost' => $cost, 'period' => $inPeriod,
                'accumulated' => $accumulated, 'book_value' => $cost - $accumulated, 'status' => $asset->status,
            ];
            $sum['cost'] += $cost;
            $sum['period'] += $inPeriod;
            $sum['accumulated'] += $accumulated;
            $sum['book_value'] += $cost - $accumulated;
        }
        $rows[] = ['id' => 'total', 'number' => 'Total', 'name' => '', 'category' => '', 'usage_date' => null, 'method' => '', 'life' => ''] + $sum + ['status' => '', 'is_total' => true];

        return $rows;
    }
}
