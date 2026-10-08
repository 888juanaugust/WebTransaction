<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Currencies;

use App\Domain\Access\MenuKey;
use App\Domain\Currency\CurrencyRates;
use App\Domain\Shared\Format;
use App\Filament\Resources\Company\Currencies\Pages\ManageCurrencies;
use App\Filament\Support\MasterResource;
use App\Models\Company\Currency;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CurrencyResource extends MasterResource
{
    protected static ?string $model = Currency::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $modelLabel = 'Currency';

    protected static ?string $recordTitleAttribute = 'code';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Currencies;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('Code'))->required()->length(3)->alpha()->unique(ignoreRecord: true)->extraInputAttributes(['style' => 'text-transform: uppercase'])
                ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state)),
            TextInput::make('symbol')->label(__('Symbol'))->required()->maxLength(10),
            TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
            TextInput::make('country')->label(__('Country'))->maxLength(80),
            TextInput::make('decimals')->label(__('Decimals'))->numeric()->integer()->minValue(0)->maxValue(4)->default(2)->required()
                ->helperText(__('Amounts in this currency are kept to this many decimals (cents).')),
            Toggle::make('is_base')->label(__('Base currency'))->helperText(__('Books are kept in the base currency.'))->inline(false)->live(),
            self::activeToggle()->inline(false),
            Repeater::make('rates')->label(__('Exchange rates'))
                ->relationship()
                ->table([
                    TableColumn::make(__('From')),
                    TableColumn::make(__('Rate'))->alignment(Alignment::End),
                    TableColumn::make(__('Tax rate (KMK)'))->alignment(Alignment::End),
                ])
                ->schema([
                    DatePicker::make('valid_from')->required()->native(false)->default(today())->distinct(),
                    TextInput::make('rate')->numeric()->required()->minValue(0.00000001),
                    TextInput::make('tax_rate')->numeric()->minValue(0.00000001)->placeholder(__('The rate')),
                ])
                ->helperText(__('Base currency per one unit. A document takes the latest rate on or before its date; the tax rate is the Minister of Finance\'s weekly rate for VAT.'))
                ->defaultItems(0)
                ->addActionLabel(__('Add rate'))
                ->visible(fn (Get $get) => ! $get('is_base'))
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('symbol')->label(__('Symbol')),
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('Country / Name'))->state(fn (Currency $r) => $r->country ? "{$r->country} · {$r->name}" : $r->name)->searchable(),
                IconColumn::make('is_base')->label(__('Base'))->boolean(),
                TextColumn::make('decimals')->label(__('Decimals'))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rate_today')->label(__('Rate today'))->alignEnd()
                    ->state(fn (Currency $r) => $r->is_base ? '' : (($rates = CurrencyRates::on($r->id, today())) ? Format::quantity($rates['rate'], 8) : '—')),
                self::activeColumn(),
            ])
            ->defaultSort('code')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()->hidden(fn (Currency $r) => $r->is_base)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCurrencies::route('/')];
    }
}
