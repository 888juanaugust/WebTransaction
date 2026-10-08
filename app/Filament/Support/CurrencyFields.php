<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Currency\Convert;
use App\Domain\Currency\Currencies;
use App\Domain\Currency\CurrencyRates;
use App\Domain\Shared\Format;
use App\Models\Company\Currency;
use App\Models\GeneralLedger\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The currency of a document, shown only while Multiple currencies is on and
 * a foreign currency exists. Amounts are typed in the document's currency;
 * on save they move to the fc_* columns (in minor units) and the base
 * columns are computed from them at the document's rate.
 */
final class CurrencyFields
{
    /** @return list<Select|TextInput> currency, rate and (for taxable documents) the tax rate */
    public static function header(bool $taxRate = true): array
    {
        $fields = [
            Select::make('currency_id')->label(__('Currency'))
                ->options(fn () => Currencies::foreignOptions())
                ->placeholder(fn () => Currencies::base()?->code ?? __('Base currency'))
                ->native(false)->live()
                ->visible(fn () => Currencies::enabled())
                ->afterStateUpdated(fn (Set $set, Get $get, $state) => self::fillRates($set, $get, $state)),
            TextInput::make('exchange_rate')->label(__('Rate'))->numeric()->minValue(0.00000001)->default(1)
                ->helperText(fn (Get $get) => __('Base currency per one :code', ['code' => Currencies::code($get('currency_id'))]))
                ->visible(fn (Get $get) => Currencies::isForeign($get('currency_id')))->required(fn (Get $get) => Currencies::isForeign($get('currency_id'))),
        ];
        if ($taxRate) {
            $fields[] = TextInput::make('tax_exchange_rate')->label(__('Tax rate (KMK)'))->numeric()->minValue(0.00000001)
                ->helperText(__('The Minister of Finance\'s rate for VAT; empty takes the rate.'))
                ->visible(fn (Get $get) => Currencies::isForeign($get('currency_id')) && (bool) $get('taxable'));
        }

        return $fields;
    }

    /** A customer's, vendor's or bank account's currency (empty: the base currency). */
    public static function select(string $helper): Select
    {
        return Select::make('currency_id')->label(__('Currency'))
            ->options(fn () => Currencies::foreignOptions())
            ->placeholder(fn () => Currencies::base()?->code ?? __('Base currency'))
            ->native(false)
            ->helperText($helper)
            ->visible(fn () => Currencies::enabled());
    }

    /** The rates on the document's date for a currency just picked (or the party's). */
    public static function fillRates(Set $set, Get $get, mixed $currencyId, string $prefix = ''): void
    {
        foreach (self::state($currencyId, $get($prefix.'trans_date')) as $field => $value) {
            if ($field !== 'currency_id') {
                $set($prefix.$field, $value);
            }
        }
    }

    /** @return array{currency_id: ?int, exchange_rate: string|int, tax_exchange_rate: ?string} a document's currency fields on a date */
    public static function state(mixed $currencyId, mixed $date = null): array
    {
        if (! Currencies::isForeign($currencyId)) {
            return ['currency_id' => null, 'exchange_rate' => 1, 'tax_exchange_rate' => null];
        }
        $rates = CurrencyRates::on((int) $currencyId, Carbon::parse($date ?: today()));

        return [
            'currency_id' => (int) $currencyId,
            'exchange_rate' => $rates['rate'] ?? 1,
            'tax_exchange_rate' => $rates !== null && $rates['tax_rate'] !== $rates['rate'] ? $rates['tax_rate'] : null,
        ];
    }

    /**
     * A document made from another (an order from a quotation, an invoice from a delivery) is in the source's
     * currency, at the rate on the new document's date (the source's rate when none is recorded).
     *
     * @return array<string, mixed>
     */
    public static function fromSource(Model $source, mixed $date = null): array
    {
        if (! array_key_exists('currency_id', $source->getAttributes()) || ! Currencies::isForeign($source->getAttribute('currency_id'))) {
            return [];
        }
        $state = self::state($source->getAttribute('currency_id'), $date);
        if (CurrencyRates::on((int) $source->getAttribute('currency_id'), Carbon::parse($date ?: today())) === null) {
            $state['exchange_rate'] = (string) $source->getAttribute('exchange_rate');
            $state['tax_exchange_rate'] = $source->getAttribute('tax_exchange_rate');
        }

        return $state;
    }

    /** When a customer or vendor is picked: its currency, with today's rates. */
    public static function forParty(Set $set, Get $get, mixed $currencyId): void
    {
        if (! Currencies::enabled()) {
            return;
        }
        $set('currency_id', Currencies::isForeign($currencyId) ? (int) $currencyId : null);
        self::fillRates($set, $get, $currencyId);
    }

    /** A bank account in a foreign currency takes the payment into its currency (a base-currency account takes any). */
    public static function forBank(Set $set, Get $get, mixed $accountId): void
    {
        $currencyId = $accountId ? Account::query()->whereKey($accountId)->value('currency_id') : null;
        if (Currencies::enabled() && Currencies::isForeign($currencyId) && ! Currencies::same($currencyId, $get('currency_id'))) {
            $set('currency_id', (int) $currencyId);
            self::fillRates($set, $get, $currencyId);
        }
    }

    /** A typed amount above zero, in any convention ("0,50" is above zero). */
    public static function isPositive(mixed $typed): bool
    {
        try {
            return is_scalar($typed) && Convert::minor($typed, 4) > 0;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** A document's amount in its own currency: the fc_* column of a foreign document, else the base one ("paid_amount" → fc_paid_amount). */
    public static function documentAmount(Model $document, string $column): string
    {
        $currencyId = $document->getAttribute('currency_id');
        if (Currencies::isForeign($currencyId) && $document->getAttribute('fc_'.$column) !== null) {
            return self::format((int) $document->getAttribute('fc_'.$column), $currencyId);
        }

        return Format::rupiah((int) $document->getAttribute($column));
    }

    public static function symbol(mixed $currencyId): string
    {
        return Currencies::isForeign($currencyId) ? (string) Currency::query()->whereKey($currencyId)->value('symbol') : Format::symbol();
    }

    public static function decimals(mixed $currencyId): int
    {
        return Currencies::decimals($currencyId);
    }

    /** An amount in minor units with its currency's decimals and no code: "1.000,50"; the base currency as Format::number. */
    public static function number(?int $minor, mixed $currencyId): string
    {
        if (! Currencies::isForeign($currencyId)) {
            return Format::number((int) $minor);
        }
        $decimals = Currencies::decimals($currencyId);

        return number_format((int) $minor / (10 ** $decimals), $decimals, Format::decimalSeparator(), Format::thousandsSeparator());
    }

    /** A unit price (major units, up to four decimals) in its currency: at least the currency's decimals, more only when the price has them. */
    public static function price(string|int|float|null $price, mixed $currencyId): string
    {
        $decimals = Currencies::decimals($currencyId);
        $text = number_format((float) $price, 4, Format::decimalSeparator(), Format::thousandsSeparator());
        [$whole, $fraction] = explode(Format::decimalSeparator(), $text) + [1 => ''];
        $fraction = str_pad(rtrim($fraction, '0'), $decimals, '0');

        return $fraction === '' ? $whole : $whole.Format::decimalSeparator().$fraction;
    }

    /** An amount in minor units, shown in its currency: "USD 1,000.00", or "Rp 1.000.000" in the base currency. */
    public static function format(?int $minor, mixed $currencyId): string
    {
        if (! Currencies::isForeign($currencyId)) {
            return Format::rupiah((int) $minor);
        }

        return Currencies::code($currencyId).' '.self::number($minor, $currencyId);
    }

    /**
     * Typed amounts become the fc_* columns (minor units) of a foreign document's row; a base one loses its fc_* values.
     *
     * @param  array<string, string>  $map  typed column → fc column
     */
    public static function toForeign(array $data, mixed $currencyId, array $map): array
    {
        $foreign = Currencies::isForeign($currencyId);
        $decimals = Currencies::decimals($currencyId);
        foreach ($map as $typed => $fc) {
            $data[$fc] = $foreign ? Convert::minor($data[$typed] ?? 0, $decimals) : null;
            if ($foreign) {
                $data[$typed] = 0; // the base amount is computed from it
            }
        }

        return $data;
    }

    /** The fc_* columns shown back as typed amounts in the document's currency. @param  array<string, string>  $map */
    public static function fromForeign(array $data, mixed $currencyId, array $map): array
    {
        if (! Currencies::isForeign($currencyId)) {
            return $data;
        }
        $decimals = Currencies::decimals($currencyId);
        foreach ($map as $typed => $fc) {
            if (($data[$fc] ?? null) !== null) {
                $data[$typed] = Convert::typed((int) $data[$fc], $decimals);
            }
        }

        return $data;
    }
}
