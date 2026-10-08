<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The standard's number block on every master and document form: a
 * number drawn from a series when saved, or typed by hand when the switch is
 * on. On edit the number is shown as it is.
 */
final class NumberFields
{
    public static function make(TransactionType $type, ?string $label = null): Group
    {
        $generator = app(NumberGenerator::class);
        $label ??= __('fields.number');

        return Group::make([
            Toggle::make('manual_number')
                ->label(__('Enter the number by hand'))
                ->default(false)
                ->live()
                ->dehydrated(false)
                ->visibleOn('create'),
            Select::make('series_id')
                ->label(__(':label format', ['label' => $label]))
                ->options(fn () => $generator->seriesFor($type, auth()->user())->pluck('name', 'id'))
                ->default(fn () => $generator->defaultSeries($type, auth()->user())?->id)
                ->native(false)
                ->required(fn (Get $get, string $operation) => $operation === 'create' && ! $get('manual_number'))
                ->visible(fn (Get $get, string $operation) => $operation === 'create' && ! $get('manual_number')),
            TextInput::make('number')
                ->label($label)
                ->maxLength(40)
                ->unique(ignoreRecord: true)
                ->required(fn (Get $get, string $operation) => $operation === 'edit' || $get('manual_number'))
                ->visible(fn (Get $get, string $operation) => $operation === 'edit' || $get('manual_number'))
                ->disabled(fn (string $operation) => $operation === 'edit'),
        ])->columns(1);
    }
}
