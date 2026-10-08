<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Company\PaymentTerm;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The discount a payment term offers for paying early ("2/10, net 30"): paid
 * within the term's discount days of the invoice date, the open balance is
 * discounted by the term's percentage. Proposed on receipt and vendor payment
 * lines; the person entering the payment can change it. Taken on the open
 * balance including tax (the accountant should confirm, see the notes).
 */
final class EarlyPaymentDiscount
{
    /** @return array{discount: int, pay: int} what to settle the open balance with when paying on $paidOn */
    public static function propose(Model $document, int $open, DateTimeInterface|string|null $paidOn): array
    {
        $term = $document->getAttribute('payment_term_id') ? PaymentTerm::query()->find($document->getAttribute('payment_term_id')) : null;
        $issued = $document->getAttribute('trans_date');
        if ($term === null || $open <= 0 || $issued === null || $paidOn === null
            || BigDecimal::of((string) $term->discount_percent)->isZero() || (int) $term->discount_days <= 0) {
            return ['discount' => 0, 'pay' => $open];
        }
        $lastDay = CarbonImmutable::parse($issued)->addDays((int) $term->discount_days);
        if (CarbonImmutable::parse($paidOn)->startOfDay()->gt($lastDay)) {
            return ['discount' => 0, 'pay' => $open];
        }
        $discount = BigDecimal::of($open)->multipliedBy((string) $term->discount_percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();

        return ['discount' => $discount, 'pay' => $open - $discount];
    }
}
