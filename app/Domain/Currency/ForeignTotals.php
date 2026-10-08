<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Documents\LineCalculator;
use App\Domain\Tax\TaxCalculator;
use App\Models\Company\TaxCode;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/**
 * The totals of a document in a foreign currency. The lines are priced in
 * the document's currency (fc_unit_price); the calculator runs in its minor
 * units and writes the fc_* columns. Every base column is then the document
 * currency's amount at the document's rate, and VAT is computed in the base
 * currency on the tax base at the tax rate (the Minister of Finance's rate,
 * else the document's), so the ledgers, the tax files and every report keep
 * reading base amounts only.
 */
final class ForeignTotals
{
    /** A priced document: quotation, order, delivery, invoice, return, goods receipt. */
    public static function refreshPriced(Model $document): void
    {
        $decimals = Currencies::decimals($document->currency_id);
        $scale = BigDecimal::ten()->power($decimals);
        $rate = (string) $document->exchange_rate;
        $taxRate = (string) ($document->tax_exchange_rate ?: $rate);
        $lines = $document->lines()->get();
        $charges = method_exists($document, 'charges') ? $document->charges()->get() : collect();
        $percent = BigDecimal::of((string) ($document->discount_percent ?? 0));

        $result = LineCalculator::compute(
            $lines->map(fn ($l) => [
                'quantity' => (string) $l->quantity,
                'unit_price' => (string) BigDecimal::of((string) ($l->fc_unit_price ?? 0))->multipliedBy($scale),
                'discount_percent' => (string) $l->discount_percent,
                'discount_amount' => 0,
                'tax_code_id' => $l->tax_code_id,
            ])->all(),
            (bool) $document->taxable,
            (bool) $document->inclusive_tax,
            (string) $percent,
            $percent->isPositive() ? 0 : (int) ($document->fc_discount_amount ?? 0),
            $charges->map(fn ($c) => ['amount' => (int) ($c->fc_amount ?? 0)])->all(),
        );

        $taxCodes = TaxCode::query()->whereIn('id', $lines->pluck('tax_code_id')->filter())->get()->keyBy('id');
        $subtotal = $discount = $dpp = $tax = 0;
        foreach ($lines as $i => $line) {
            $fc = $result['lines'][$i];
            $amount = Convert::toBase((int) $fc['amount'], $rate, $decimals);
            $share = Convert::toBase((int) $fc['header_discount'], $rate, $decimals);
            $code = $document->taxable && $line->tax_code_id ? $taxCodes->get($line->tax_code_id) : null;
            $taxed = TaxCalculator::forLine(Convert::toBase((int) $fc['amount'] - (int) $fc['header_discount'], $taxRate, $decimals), $code, (bool) $document->inclusive_tax);
            $line->forceFill([
                'unit_price' => Convert::priceToBase($line->fc_unit_price, $rate),
                'discount_amount' => Convert::toBase((int) $fc['discount_amount'], $rate, $decimals),
                'header_discount' => $share,
                'amount' => $amount,
                'dpp_amount' => $taxed->dpp,
                'tax_amount' => $taxed->tax,
                'fc_discount_amount' => (int) $fc['discount_amount'],
                'fc_header_discount' => (int) $fc['header_discount'],
                'fc_amount' => (int) $fc['amount'],
                'fc_tax_amount' => (int) $fc['tax_amount'],
            ])->saveQuietly();
            $subtotal += $amount;
            $discount += $share;
            $dpp += $taxed->dpp;
            $tax += $taxed->tax;
        }
        $chargesTotal = 0;
        foreach ($charges as $charge) {
            $base = Convert::toBase((int) ($charge->fc_amount ?? 0), $rate, $decimals);
            $charge->forceFill(['amount' => $base])->saveQuietly();
            $chargesTotal += $base;
        }

        $document->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'charges_total' => $chargesTotal,
            'dpp_total' => $dpp,
            'tax_total' => $tax,
            'total' => $document->inclusive_tax ? $subtotal - $discount + $chargesTotal : $subtotal - $discount + $tax + $chargesTotal,
            'fc_subtotal' => $result['subtotal'],
            'fc_discount_amount' => $result['discount_amount'],
            'fc_charges_total' => $result['charges_total'],
            'fc_tax_total' => $result['tax_total'],
            'fc_total' => $result['total'],
        ])->saveQuietly();
    }

    /** A down payment: one amount (fc_amount, typed in the currency) with its tax code. */
    public static function refreshDownPayment(Model $downPayment): void
    {
        $decimals = Currencies::decimals($downPayment->currency_id);
        $rate = (string) $downPayment->exchange_rate;
        $taxRate = (string) ($downPayment->tax_exchange_rate ?: $rate);
        $code = $downPayment->taxable ? $downPayment->taxCode : null;
        $inclusive = (bool) $downPayment->inclusive_tax;
        $fc = TaxCalculator::forLine((int) $downPayment->fc_amount, $code, $inclusive);
        $taxed = TaxCalculator::forLine(Convert::toBase((int) $downPayment->fc_amount, $taxRate, $decimals), $code, $inclusive);
        $amount = Convert::toBase((int) $downPayment->fc_amount, $rate, $decimals);
        $subtotal = $inclusive ? $amount - $taxed->tax : $amount;
        $downPayment->forceFill([
            'amount' => $amount,
            'subtotal' => $subtotal,
            'dpp_total' => $taxed->dpp,
            'tax_total' => $taxed->tax,
            'total' => $subtotal + $taxed->tax,
            'fc_subtotal' => $fc->base,
            'fc_tax_total' => $fc->tax,
            'fc_total' => $fc->gross,
        ])->saveQuietly();
    }
}
