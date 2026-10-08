<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Currency;
use Illuminate\Database\Eloquent\Model;

/**
 * Which currencies are in use. Documents in a foreign currency are offered
 * only when Multiple currencies is on and an active currency other than the
 * base one exists, so an installation with only its base currency sees
 * nothing new. A document's currency_id of null (or the base currency's)
 * means the base currency.
 */
final class Currencies
{
    public static function enabled(): bool
    {
        return (bool) app(Preferensi::class)->isOn(PreferensiKey::MultiCurrency)
            && Currency::query()->where('is_active', true)->where('is_base', false)->exists();
    }

    public static function base(): ?Currency
    {
        return Currency::query()->where('is_base', true)->first();
    }

    public static function isForeign(int|string|null $currencyId): bool
    {
        return filled($currencyId) && (int) $currencyId !== (int) self::base()?->id;
    }

    /** Whether a document (or account, or party) is in a foreign currency. */
    public static function isForeignModel(?Model $model): bool
    {
        return $model !== null && self::isForeign($model->getAttribute('currency_id'));
    }

    /** Whether two documents are in the same currency (null and the base currency's id both mean the base currency). */
    public static function same(int|string|null $a, int|string|null $b): bool
    {
        $foreignA = self::isForeign($a);

        return $foreignA === self::isForeign($b) && (! $foreignA || (int) $a === (int) $b);
    }

    public static function decimals(int|string|null $currencyId): int
    {
        return self::isForeign($currencyId) ? (int) (Currency::query()->whereKey($currencyId)->value('decimals') ?? 2) : 0;
    }

    /** @return array<int, string> id → code, the active currencies other than the base one */
    public static function foreignOptions(): array
    {
        return Currency::query()->where('is_active', true)->where('is_base', false)->orderBy('code')->pluck('code', 'id')->all();
    }

    public static function code(int|string|null $currencyId): string
    {
        return (string) (filled($currencyId) ? Currency::query()->whereKey($currencyId)->value('code') : self::base()?->code);
    }
}
