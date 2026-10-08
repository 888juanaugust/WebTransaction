<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Access\MenuRegistry;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Currency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * How numbers and dates read on screen: DESIGN.md's rules, under the Other
 * tab of Preferences. Numbers follow the chosen convention (Indonesian by
 * default: 18.450.000 with decimals after a comma; or 18,450,000.00);
 * quantities show up to the chosen decimals, trailing zeros dropped; prices
 * the chosen decimals. Date inputs use the chosen date format; tables read
 * "17 Oct 2026". Money carries the base currency's symbol. Read once per
 * request; Indonesian when Preferences cannot be read (no database yet).
 */
final class Format
{
    /** The default date input format; inputs follow dateInputFormat(). */
    public const DATE_INPUT = 'd/m/Y';

    public const DATE_TABLE = 'j M Y';

    private const FALLBACK_SYMBOL = 'Rp';

    private static ?string $symbol = null;

    /** @var array{thousands: string, decimal: string, quantity_decimals: int, price_decimals: int, date_input: string}|null */
    private static ?array $conventions = null;

    /** The base currency's symbol ("Rp" until a base currency is set), read once per request. */
    public static function symbol(): string
    {
        if (self::$symbol === null) {
            try {
                self::$symbol = (string) (Currency::query()->where('is_base', true)->value('symbol') ?: self::FALLBACK_SYMBOL);
            } catch (Throwable) {
                self::$symbol = self::FALLBACK_SYMBOL;
            }
        }

        return self::$symbol;
    }

    /** Forget what was read: after the base currency or the format preferences change, and between tests. */
    public static function forget(): void
    {
        self::$symbol = null;
        self::$conventions = null;
    }

    /** @deprecated the same as forget() */
    public static function forgetSymbol(): void
    {
        self::forget();
    }

    public static function thousandsSeparator(): string
    {
        return self::conventions()['thousands'];
    }

    public static function decimalSeparator(): string
    {
        return self::conventions()['decimal'];
    }

    /** The date inputs' format, from Preferences: "d/m/Y" unless a company chose another. */
    public static function dateInputFormat(): string
    {
        return self::conventions()['date_input'];
    }

    /** @return array{thousands: string, decimal: string, quantity_decimals: int, price_decimals: int, date_input: string} */
    private static function conventions(): array
    {
        if (self::$conventions === null) {
            $conventions = ['thousands' => '.', 'decimal' => ',', 'quantity_decimals' => 4, 'price_decimals' => 0, 'date_input' => self::DATE_INPUT];
            try {
                $prefs = app(Preferensi::class);
                if ($prefs->get(PreferensiKey::DecimalFormat) === 'en') {
                    $conventions['thousands'] = ',';
                    $conventions['decimal'] = '.';
                }
                $conventions['quantity_decimals'] = max(0, min(4, (int) $prefs->get(PreferensiKey::QuantityDecimals)));
                $conventions['price_decimals'] = max(0, min(4, (int) $prefs->get(PreferensiKey::PriceDecimals)));
                $conventions['date_input'] = (string) ($prefs->get(PreferensiKey::DateFormat) ?: self::DATE_INPUT);
            } catch (Throwable) {
                // Preferences unreadable (no database yet): the Indonesian defaults.
            }
            self::$conventions = $conventions;
        }

        return self::$conventions;
    }

    /** An amount with the base currency's symbol: "Rp 18.450.000", "-Rp 500". */
    public static function money(?int $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return ($amount < 0 ? '-' : '').self::symbol().' '.number_format(abs($amount), 0, self::decimalSeparator(), self::thousandsSeparator());
    }

    /** @deprecated kept for the call sites that grew up with it; the same as money() */
    public static function rupiah(?int $amount): string
    {
        return self::money($amount);
    }

    public static function number(?int $amount): string
    {
        return $amount === null ? '' : Money::format($amount);
    }

    /** A quantity with up to the chosen decimals (four by default), trailing zeros dropped: 12 → "12", 2.5 → "2,5". */
    public static function quantity(string|int|float|null $qty, ?int $maxDecimals = null): string
    {
        if ($qty === null || $qty === '') {
            return '';
        }
        $decimal = self::decimalSeparator();
        $text = number_format((float) $qty, $maxDecimals ?? self::conventions()['quantity_decimals'], $decimal, self::thousandsSeparator());
        if (str_contains($text, $decimal)) {
            $text = rtrim(rtrim($text, '0'), $decimal);
        }

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /** A unit price with the chosen decimals (none by default): 150000 → "150.000". */
    public static function price(string|int|float|null $price): string
    {
        return $price === null || $price === '' ? '' : number_format((float) $price, self::conventions()['price_decimals'], self::decimalSeparator(), self::thousandsSeparator());
    }

    /** A percentage for display: "12", "2,5". */
    public static function percent(string|int|float|null $value): string
    {
        return $value === null || $value === '' ? '' : self::quantity($value, 2).'%';
    }

    public static function date(DateTimeInterface|string|null $date): string
    {
        return self::carbon($date)?->translatedFormat(self::DATE_TABLE) ?? '';
    }

    public static function dateInput(DateTimeInterface|string|null $date): string
    {
        return self::carbon($date)?->format(self::dateInputFormat()) ?? '';
    }

    /** A month's name in the current language (1 = January). */
    public static function monthName(int $month): string
    {
        return CarbonImmutable::create(2000, $month, 1)->translatedFormat('F');
    }

    /** @return array<int, string> 1..12 → month name, for a month picker */
    public static function months(): array
    {
        return array_combine(range(1, 12), array_map(self::monthName(...), range(1, 12)));
    }

    public static function dateTime(DateTimeInterface|string|null $date): string
    {
        return self::carbon($date)?->translatedFormat('j M Y H:i') ?? '';
    }

    private static function carbon(DateTimeInterface|string|null $date): ?CarbonInterface
    {
        if ($date === null || $date === '') {
            return null;
        }

        return $date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);
    }

    /** A stored code as the user reads it: lang/<locale>/status.php under the group, else the code itself made readable ("down_payment" → "Down payment"). */
    public static function code(?string $value, string $group): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $key = "status.{$group}.{$value}";

        return Lang::has($key) ? (string) __($key) : ucfirst(str_replace('_', ' ', $value));
    }

    /** A document type (morph alias) by its screen's name: "sales_receipt" → "Sales Receipts"; else the alias made readable. */
    public static function documentType(?string $alias): string
    {
        if ($alias === null || $alias === '') {
            return '';
        }
        $model = Relation::getMorphedModel($alias);
        $key = $model !== null ? app(MenuRegistry::class)->menuKeyForModel($model) : null;

        return $key?->label() ?? self::code($alias, 'record');
    }
}
