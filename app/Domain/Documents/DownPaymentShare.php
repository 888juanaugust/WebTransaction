<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Shared\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * What an invoice deducts of a down payment, split as the down payment itself is: the share of its price (net),
 * of its tax base (DPP) and of its VAT, in proportion to its own totals. Stored on the deduction; the posting,
 * the VAT return and the e-Faktur settlement row read the stored split.
 */
final class DownPaymentShare
{
    /** @return array{net_amount: int, dpp_amount: int, tax_amount: int} */
    public static function of(int $applied, Model $downPayment): array
    {
        $total = (int) $downPayment->getAttribute('total');
        if ($total <= 0) {
            return ['net_amount' => $applied, 'dpp_amount' => 0, 'tax_amount' => 0];
        }
        $net = Money::mulDiv($applied, (int) $downPayment->getAttribute('subtotal'), $total);

        return ['net_amount' => $net, 'dpp_amount' => Money::mulDiv($applied, (int) $downPayment->getAttribute('dpp_total'), $total), 'tax_amount' => $applied - $net];
    }
}
