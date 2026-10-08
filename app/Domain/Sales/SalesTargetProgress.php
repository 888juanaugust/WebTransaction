<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Format;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesReturnLine;
use App\Models\Sales\SalesTarget;
use App\Models\Sales\SalesTargetLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * How far a sales target has come: for each target line, the net sales value
 * and base quantity invoiced within the target's dates (and branch), less
 * returns, for the line's item, item category, salesperson or month.
 */
final class SalesTargetProgress
{
    /**
     * @return list<array{line: SalesTargetLine, label: string, target_quantity: string, quantity: string, target_value: int, value: int, percent: int|null}>
     */
    public static function of(SalesTarget $target): array
    {
        $rows = [];
        foreach ($target->lines()->with(['item', 'itemCategory', 'salesman'])->get() as $line) {
            [$value, $quantity] = self::actual($target, $line);
            $targetValue = (int) $line->value;
            $targetQuantity = BigDecimal::of((string) $line->quantity);
            $percent = match (true) {
                $targetValue > 0 => (int) round($value * 100 / $targetValue),
                $targetQuantity->isPositive() => BigDecimal::of($quantity)->multipliedBy(100)->dividedBy($targetQuantity, 0, RoundingMode::HalfUp)->toInt(),
                default => null,
            };
            $rows[] = [
                'line' => $line,
                'label' => self::label($target, $line),
                'target_quantity' => (string) $targetQuantity,
                'quantity' => $quantity,
                'target_value' => $targetValue,
                'value' => $value,
                'percent' => $percent,
            ];
        }

        return $rows;
    }

    /** @return array{0: int, 1: string} net value and base quantity */
    private static function actual(SalesTarget $target, SalesTargetLine $line): array
    {
        $value = 0;
        $quantity = BigDecimal::zero();
        foreach ([[SalesInvoiceLine::class, 'salesInvoice', 1], [SalesReturnLine::class, 'salesReturn', -1]] as [$class, $document, $sign]) {
            $lines = $class::query()->with($document)
                ->whereHas($document, fn (Builder $q) => $q
                    ->when($target->from_date, fn ($q) => $q->where('trans_date', '>=', $target->from_date->toDateString()))
                    ->where('trans_date', '<=', $target->to_date->toDateString())
                    ->when($target->branch_id, fn ($q) => $q->where('branch_id', $target->branch_id))
                    ->when($target->target_type === 'per_month' && $line->month, fn ($q) => $q->whereRaw('EXTRACT(MONTH FROM trans_date) = ?', [(int) $line->month])))
                ->when($target->target_type === 'per_item' && $line->item_id, fn ($q) => $q->where('item_id', $line->item_id))
                ->when($target->target_type === 'per_category' && $line->item_category_id, fn ($q) => $q->whereHas('item', fn ($i) => $i->where('category_id', $line->item_category_id)))
                ->when($target->target_type === 'per_salesman' && $line->salesman_id, fn ($q) => $q->where('salesman_id', $line->salesman_id))
                ->get();
            foreach ($lines as $l) {
                $value += $sign * $l->netAmount();
                $quantity = $quantity->plus(BigDecimal::of((string) $l->base_quantity)->multipliedBy($sign));
            }
        }

        return [$value, (string) $quantity->toScale(4)];
    }

    private static function label(SalesTarget $target, SalesTargetLine $line): string
    {
        return match ($target->target_type) {
            'per_item' => $line->item?->name ?? '—',
            'per_category' => $line->itemCategory?->name ?? '—',
            'per_salesman' => $line->salesman?->name ?? '—',
            'per_month' => $line->month ? Format::monthName((int) $line->month) : '—',
            default => '—',
        };
    }
}
