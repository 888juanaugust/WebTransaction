<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Settlement\SettlementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;

/**
 * The base value a payment in a foreign currency takes off a document: its
 * carrying value. A payment that clears what is open in the document's
 * currency takes the whole open base balance, so no rounding is ever left
 * behind; a part payment takes its share at the document's own rate. What
 * the payment is worth at its own rate, less this, is the realised exchange
 * difference.
 */
final class FxSettlement
{
    public function __construct(private readonly SettlementService $settlement) {}

    /** @param  Model|null  $payment  the receipt or payment being saved, whose earlier allocations do not count */
    public function carrying(Model $document, int $foreignSettled, ?Model $payment = null): int
    {
        $openForeign = $this->settlement->foreignBalance($document, $payment);
        if ($foreignSettled === $openForeign) {
            return $this->settlement->balanceExcept($document, $payment);
        }
        $credit = method_exists($document, 'isCredit') && $document->isCredit();
        $base = (int) $document->getAttribute('total') - (int) ($document->getAttribute('down_payment_total') ?? 0);
        $foreign = $this->settlement->foreignTotal($document);
        if ($foreign === 0) {
            return 0;
        }

        // In arbitrary precision: large amounts times a rate overflow a 64-bit integer.
        return BigDecimal::of($foreignSettled)->multipliedBy($credit ? -$base : $base)->dividedBy($credit ? -$foreign : $foreign, 0, RoundingMode::HalfUp)->toInt();
    }
}
