<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Format;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\RawJs;

/** A whole-amount input that groups thousands as Preferences say (18.450.000, or 18,450,000) and saves the plain number. */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        $decimal = Format::decimalSeparator();
        $thousands = Format::thousandsSeparator();

        return TextInput::make($name)
            ->mask(RawJs::make("\$money(\$input, '{$decimal}', '{$thousands}', 0)"))
            ->stripCharacters($thousands)
            ->numeric();
    }

    /**
     * An amount in a document's own currency: as many decimals as that
     * currency has (none in the base currency, so it reads exactly like
     * make()), saved as a plain decimal ("1000.50").
     *
     * @param  Closure(Get): int  $decimals
     */
    public static function inCurrency(string $name, Closure $decimals): TextInput
    {
        $decimal = Format::decimalSeparator();
        $thousands = Format::thousandsSeparator();

        return TextInput::make($name)
            ->mask(fn (Get $get) => RawJs::make("\$money(\$input, '{$decimal}', '{$thousands}', ".$decimals($get).')'))
            ->stripCharacters($thousands)
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? $state : str_replace($decimal, '.', (string) $state))
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($decimal): void {
                if ($value !== null && $value !== '' && ! is_numeric(str_replace($decimal, '.', (string) $value))) {
                    $fail(__('Enter an amount.'));
                }
            });
    }
}
