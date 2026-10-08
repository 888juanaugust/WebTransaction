<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Currency\Convert;
use App\Domain\Currency\Currencies;
use App\Domain\Settlement\EarlyPaymentDiscount;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Format;
use Closure;
use DateTimeInterface;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * The lines of a receipt or payment: each settles one open document, in the
 * payment's currency. A foreign payment's amounts are typed in its currency
 * and kept as fc_amount and fc_discount; the base amounts are the carrying
 * value the posting takes off the document.
 */
final class SettlementLineFields
{
    private const FOREIGN = ['amount' => 'fc_amount', 'discount' => 'fc_discount'];

    /** What is open on a document, in its own currency (minor units when foreign; negative for a credit). */
    public static function open(Model $document): int
    {
        $settlement = app(SettlementService::class);

        return $settlement->isForeign($document) ? $settlement->foreignBalance($document) : $settlement->balance($document);
    }

    /** A document's total, in its own currency. */
    public static function total(Model $document): int
    {
        $settlement = app(SettlementService::class);

        return $settlement->isForeign($document) ? $settlement->foreignTotal($document) : (int) ($document->getAttribute('total') ?? $document->getAttribute('amount') ?? 0);
    }

    /** "INV-1 · 05/10/2026 · USD 1.000,00 · open USD 500,00", the open item as a picker shows it. */
    public static function label(Model $document, string $number, mixed $date, int $open, ?string $kind = null): string
    {
        $currencyId = $document->getAttribute('currency_id');

        return $number.' · '.Format::date($date).' · '.($kind ?? CurrencyFields::format(self::total($document), $currencyId)).' · '.__('open').' '.CurrencyFields::format($open, $currencyId);
    }

    /** Whether a document can be settled by a payment in the given currency. */
    public static function inCurrency(Model $document, mixed $currencyId): bool
    {
        return Currencies::same($document->getAttribute('currency_id'), $currencyId);
    }

    /** The pay and discount proposed for a document paid on a date (the early-payment discount within its days), as typed amounts. */
    public static function proposal(Model $document, DateTimeInterface|string|null $paidOn): array
    {
        $proposal = EarlyPaymentDiscount::propose($document, self::open($document), $paidOn);
        $currencyId = $document->getAttribute('currency_id');
        if (! Currencies::isForeign($currencyId)) {
            return ['amount' => $proposal['pay'], 'discount' => $proposal['discount']];
        }
        $decimals = Currencies::decimals($currencyId);

        return ['amount' => Convert::typed($proposal['pay'], $decimals), 'discount' => Convert::typed($proposal['discount'], $decimals)];
    }

    /** An amount of the line, typed in the payment's currency. */
    public static function amount(string $name, string $label): TextInput
    {
        return MoneyInput::inCurrency($name, fn (Get $get) => CurrencyFields::decimals($get('../../currency_id')))->label($label)->default(0);
    }

    /** The lines' "pay" column summed, in the payment's currency: "Rp 1.500.000" or "USD 1.000,00". */
    public static function sum(mixed $lines, mixed $currencyId): string
    {
        $decimals = CurrencyFields::decimals($currencyId);
        $total = 0;
        foreach ((array) $lines as $line) {
            try {
                $total += Convert::minor(is_scalar($line['amount'] ?? null) ? $line['amount'] : 0, $decimals);
            } catch (\InvalidArgumentException) {
                // half-typed: not counted yet
            }
        }

        return CurrencyFields::format($total, $currencyId);
    }

    /** No negative amount, except against a credit (a return or credit note taken off the payment). */
    public static function notNegativeUnlessCredit(Closure $document): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($document): void {
            $doc = $document();
            if ($doc !== null && method_exists($doc, 'isCredit') && $doc->isCredit()) {
                return;
            }
            if (is_scalar($value) && str_starts_with(trim((string) $value), '-')) {
                $fail(__('The amount cannot be negative.'));
            }
        };
    }

    public static function toForeign(array $data, mixed $currencyId): array
    {
        return CurrencyFields::toForeign($data, $currencyId, self::FOREIGN);
    }

    public static function fromForeign(array $data, mixed $currencyId): array
    {
        return CurrencyFields::fromForeign($data, $currencyId, self::FOREIGN);
    }
}
