<?php

declare(strict_types=1);

namespace App\Domain\CashBank;

use App\Domain\Tax\TaxCalculator;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Tax per line on payments, receipts and expense accruals: the line's
 * amount is before tax, or includes it when the header says so; the tax
 * base and tax are stored on the line, the tax goes to the tax code's VAT
 * account, and the document's total is what is actually paid. A line that
 * settles a document (an accrual being paid) carries no tax of its own.
 *
 * The VAT treatment of these lines is for the company's accountant to
 * confirm (see docs/standard/_notes/README.md).
 */
final class LineTax
{
    /**
     * Recomputes each line's tax base and tax; returns the document's total, tax included.
     *
     * @param  (Closure(Model): bool)|null  $untaxed  lines that carry no tax
     */
    public static function refresh(Model $document, ?Closure $untaxed = null): int
    {
        $inclusive = (bool) $document->getAttribute('inclusive_tax');
        $total = 0;
        foreach ($document->lines()->with('taxCode')->get() as $line) {
            if ($untaxed !== null && $untaxed($line)) {
                $line->forceFill(['tax_code_id' => null, 'dpp_amount' => 0, 'tax_amount' => 0])->saveQuietly();
                $total += (int) $line->amount;

                continue;
            }
            $result = TaxCalculator::forLine((int) $line->amount, $line->taxCode, $inclusive);
            $line->forceFill(['dpp_amount' => $result->dpp, 'tax_amount' => $result->tax])->saveQuietly();
            $total += $result->gross;
        }

        return $total;
    }

    /** The part of a line its own account takes: the amount without its tax. */
    public static function net(Model $line, bool $inclusive): int
    {
        return (int) $line->getAttribute('amount') - ($inclusive ? (int) $line->getAttribute('tax_amount') : 0);
    }
}
