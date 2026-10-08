<?php

declare(strict_types=1);

namespace App\Filament\Support\Columns;

use App\Domain\Currency\Currencies;
use App\Filament\Support\CurrencyFields;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/** A document list's Currency and amount-in-its-currency columns, offered (hidden until picked) while foreign currencies are in use. */
final class InCurrency
{
    /** @return list<TextColumn> */
    public static function make(string $fcColumn = 'fc_total'): array
    {
        if (! Currencies::enabled()) {
            return [];
        }

        return [
            TextColumn::make('currency_code')->label(__('Currency'))
                ->state(fn (Model $record) => Currencies::code($record->getAttribute('currency_id')))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make($fcColumn)->label(__('Total in currency'))->alignEnd()
                ->formatStateUsing(fn ($state, Model $record): string => $state === null ? '' : CurrencyFields::format((int) $state, $record->getAttribute('currency_id')))
                ->extraCellAttributes(['class' => 'ae-money'])
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}
