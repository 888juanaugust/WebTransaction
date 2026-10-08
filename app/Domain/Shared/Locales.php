<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * The languages the screens come in: the company's default (Preferences →
 * Other → Language), which a user may override on their profile. Number and
 * date formats are their own preferences.
 */
final class Locales
{
    /** @return array<string, string> locale → its own name */
    public static function names(): array
    {
        return ['en' => 'English', 'id' => 'Bahasa Indonesia'];
    }

    public static function companyDefault(): string
    {
        try {
            $locale = (string) app(Preferensi::class)->get(PreferensiKey::Language);
        } catch (Throwable) {
            $locale = ''; // preferences unreadable (no database yet)
        }

        return isset(self::names()[$locale]) ? $locale : (string) config('app.locale', 'en');
    }

    public static function forUser(?User $user): string
    {
        $own = $user?->locale;

        return is_string($own) && isset(self::names()[$own]) ? $own : self::companyDefault();
    }

    /** The application, the translator and Carbon (month and day names) in one language. */
    public static function apply(string $locale): void
    {
        if (! isset(self::names()[$locale])) {
            return;
        }
        App::setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);
    }

    /** Runs a callback in a language (a document sent to a customer goes in the company's), then restores the current one. */
    public static function using(string $locale, callable $callback): mixed
    {
        $previous = App::getLocale();
        self::apply($locale);
        try {
            return $callback();
        } finally {
            self::apply($previous);
        }
    }
}
