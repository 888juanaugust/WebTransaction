<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Shared\Money;
use App\Domain\Tax\TaxCalculator;
use App\Models\Company\TaxCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The arithmetic of a priced document, in one place: a line's amount is
 * quantity × unit price minus its discount, rounded to whole rupiah; tax is
 * computed per line (never on the total); the header discount is spread
 * over the lines by largest remainder; charges add to the total.
 */
final class LineCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $lines  quantity, unit_price, discount_percent, discount_amount, tax_code_id
     * @param  array<int, array<string, mixed>>  $charges  amount
     * @return array{lines: array<int, array<string, mixed>>, subtotal: int, discount_amount: int, charges_total: int, dpp_total: int, tax_total: int, total: int}
     */
    public static function compute(array $lines, bool $taxable, bool $inclusive, string|int|float $headerDiscountPercent = 0, int $headerDiscountAmount = 0, array $charges = []): array
    {
        $taxCodes = TaxCode::query()->whereIn('id', array_filter(array_column($lines, 'tax_code_id')))->get()->keyBy('id');

        $out = [];
        $subtotal = 0;
        foreach ($lines as $i => $line) {
            $qty = BigDecimal::of((string) ($line['quantity'] ?? 0));
            $price = BigDecimal::of((string) ($line['unit_price'] ?? 0));
            $gross = $qty->multipliedBy($price)->toScale(0, RoundingMode::HalfUp)->toInt();

            // As on the header, the percentage decides; a fixed amount counts only where no percentage is
            // given (the stored amount comes back from the form, and would otherwise freeze the first one).
            $percent = (string) ($line['discount_percent'] ?? 0);
            $discount = BigDecimal::of($percent === '' ? '0' : $percent)->isPositive()
                ? BigDecimal::of($gross)->multipliedBy($percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt()
                : Money::parse((string) ($line['discount_amount'] ?? 0));
            $amount = $gross - $discount;
            $subtotal += $amount;

            $out[$i] = $line + ['gross' => $gross];
            $out[$i]['discount_amount'] = $discount;
            $out[$i]['amount'] = $amount;
        }

        // The header discount, spread over the lines in proportion, largest remainder.
        $headerDiscount = $headerDiscountAmount;
        $hp = BigDecimal::of((string) ($headerDiscountPercent === '' ? 0 : $headerDiscountPercent));
        if ($headerDiscount === 0 && $hp->isPositive()) {
            $headerDiscount = BigDecimal::of($subtotal)->multipliedBy($hp)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
        }
        $shares = $headerDiscount > 0 && $subtotal > 0
            ? Money::allocate($headerDiscount, array_map(fn ($l) => max(0, (int) $l['amount']), $out))
            : array_fill(0, count($out), 0);

        $dppTotal = 0;
        $taxTotal = 0;
        $k = 0;
        foreach ($out as $i => $line) {
            $share = $shares[$k++] ?? 0;
            $taxed = (int) $line['amount'] - $share;
            $out[$i]['header_discount'] = $share;
            $code = $taxable && ! empty($line['tax_code_id']) ? ($taxCodes[$line['tax_code_id']] ?? null) : null;
            $result = TaxCalculator::forLine($taxed, $code, $inclusive);
            $out[$i]['dpp_amount'] = $result->dpp;
            $out[$i]['tax_amount'] = $result->tax;
            $out[$i]['net_amount'] = $result->base;
            $dppTotal += $result->dpp;
            $taxTotal += $result->tax;
        }

        $chargesTotal = 0;
        foreach ($charges as $charge) {
            $chargesTotal += Money::parse((string) ($charge['amount'] ?? 0));
        }

        $total = $inclusive
            ? $subtotal - $headerDiscount + $chargesTotal
            : $subtotal - $headerDiscount + $taxTotal + $chargesTotal;

        return [
            'lines' => $out,
            'subtotal' => $subtotal,
            'discount_amount' => $headerDiscount,
            'charges_total' => $chargesTotal,
            'dpp_total' => $dppTotal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ];
    }
}
