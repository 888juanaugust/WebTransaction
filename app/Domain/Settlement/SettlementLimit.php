<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Shared\Format;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * What one receipt or payment line may settle: never more than the document has open, never a negative amount
 * against an ordinary document (only a credit, such as a return, is applied with negative amounts, and never
 * beyond the credit left). Checked when the payment posts, after its earlier revision is superseded, so the open
 * balance is what is open without it; a foreign document is checked in its own currency.
 */
final class SettlementLimit
{
    public function __construct(private readonly SettlementService $settlement) {}

    /** The document settled belongs to the payment's own customer or vendor. */
    public function assertParty(Model $document, ?Model $party): void
    {
        $owns = match (true) {
            $party === null => false,
            $document->getAttribute('party_type') !== null => $document->getAttribute('party_type') === $party->getMorphClass() && (int) $document->getAttribute('party_id') === (int) $party->getKey(),
            default => (int) ($document->getAttribute('customer_id') ?? $document->getAttribute('vendor_id')) === (int) $party->getKey()
                && $document->getAttribute($party->getForeignKey()) !== null,
        };
        if (! $owns) {
            $number = (string) ($document->getAttribute('number') ?? (method_exists($document, 'postingNumber') ? $document->postingNumber() : ''));

            throw new RuntimeException(__(':number belongs to another customer or vendor.', ['number' => $number]));
        }
    }

    public function assertLine(Model $document, int $amount, int $discount, ?int $fcAmount = null, ?int $fcDiscount = null): void
    {
        $foreign = $this->settlement->isForeign($document);
        $settles = $foreign ? (int) $fcAmount + (int) $fcDiscount : $amount + $discount;
        $open = $foreign ? $this->settlement->foreignBalance($document) : $this->settlement->balance($document);
        $number = (string) ($document->getAttribute('number') ?? (method_exists($document, 'postingNumber') ? $document->postingNumber() : ''));
        $credit = method_exists($document, 'isCredit') && $document->isCredit();

        if ($credit ? ($amount > 0 || $discount > 0) : ($amount < 0 || $discount < 0)) {
            throw new RuntimeException(__(':number: the amount cannot be :sign.', ['number' => $number, 'sign' => $credit ? __('positive for a credit') : __('negative')]));
        }
        if (abs($settles) > abs($open)) {
            throw new RuntimeException(__(':number has :open open; a payment line cannot settle more.', ['number' => $number, 'open' => $foreign ? Format::number(abs($open)) : Format::money(abs($open))]));
        }
    }
}
