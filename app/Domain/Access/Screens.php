<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Filament\Modul;

/** Every screen key of this installation: the template's MenuKey and the client's own enums (config/client.php "screens"). */
final class Screens
{
    /** @return list<class-string<ScreenKey>> */
    public static function enums(): array
    {
        $enums = [MenuKey::class];
        foreach ((array) config('client.screens', []) as $enum) {
            if (is_string($enum) && enum_exists($enum) && is_subclass_of($enum, ScreenKey::class)) {
                $enums[] = $enum;
            }
        }

        return $enums;
    }

    /** @return list<ScreenKey> */
    public static function all(): array
    {
        $keys = [];
        foreach (self::enums() as $enum) {
            array_push($keys, ...$enum::cases());
        }

        return $keys;
    }

    public static function find(string $value): ?ScreenKey
    {
        foreach (self::enums() as $enum) {
            if (($key = $enum::tryFrom($value)) !== null) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<ScreenKey> the screens of one module group, in their order */
    public static function of(Modul $modul): array
    {
        $keys = array_values(array_filter(self::all(), fn (ScreenKey $key) => $key->modul() === $modul));
        usort($keys, fn (ScreenKey $a, ScreenKey $b) => $a->sort() <=> $b->sort());

        return $keys;
    }
}
