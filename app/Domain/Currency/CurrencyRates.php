<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Models\Company\CurrencyRate;
use Carbon\CarbonInterface;

/** A currency's rate on a date: the latest one set on or before it; the tax rate falls back to the book rate. */
final class CurrencyRates
{
    /** @return array{rate: string, tax_rate: string}|null base currency per one unit */
    public static function on(int $currencyId, CarbonInterface|string $date): ?array
    {
        $row = CurrencyRate::query()->where('currency_id', $currencyId)->where('valid_from', '<=', is_string($date) ? $date : $date->toDateString())
            ->orderByDesc('valid_from')->first();
        if ($row === null) {
            return null;
        }

        return ['rate' => (string) $row->rate, 'tax_rate' => (string) ($row->tax_rate ?? $row->rate)];
    }
}
