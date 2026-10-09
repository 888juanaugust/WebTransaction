<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\CustomerPriceRules;

use App\Client\Filament\Resources\CustomerPriceRules\Pages\ManageCustomerPriceRules;
use App\Client\Models\CustomerPriceRule;
use App\Client\Screens\CentralScreen;
use App\Domain\Shared\Format;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MoneyInput;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/** Customer Prices: the deals made with one customer — a price or a discount, for one item or every item, from a quantity, between dates. */
class CustomerPriceRuleResource extends MasterResource
{
    protected static ?string $model = CustomerPriceRule::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'Customer price';

    protected static ?string $recordTitleAttribute = 'reason';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::CustomerPrices;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->label(__('fields.customer'))->options(fn () => Customer::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->required()->native(false),
            Select::make('item_id')->label(__('Item'))->options(fn () => Item::query()->where('is_active', true)->orderBy('number')->get()->mapWithKeys(fn (Item $i) => [$i->id => "{$i->number} — {$i->name}"]))
                ->searchable()->native(false)->placeholder(__('Every item'))
                ->helperText(__('Leave empty for a deal on every item.')),
            TextInput::make('min_base_quantity')->label(__('From quantity (base units)'))->numeric()->minValue(0)->default(1)->required(),
            MoneyInput::make('price')->label(__('Price per base unit'))->prefix(Format::symbol())
                ->rule(fn (Get $get) => Rule::requiredIf(blank($get('discount_percent'))))
                ->helperText(__('A price, or a discount below; not both.'))->live(onBlur: true),
            TextInput::make('discount_percent')->label(__('Discount (%)'))->numeric()->minValue(0)->maxValue(100)
                ->rule(fn (Get $get) => Rule::prohibitedIf(filled($get('price'))))->live(onBlur: true),
            DatePicker::make('effective_from')->label(__('Effective from'))->native(false),
            DatePicker::make('effective_until')->label(__('Effective until'))->native(false)->afterOrEqual('effective_from'),
            TextInput::make('reason')->label(__('Reason'))->maxLength(255)->required(),
            self::activeToggle()->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'item']))
            ->columns([
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('item.number')->label(__('Item'))->fontFamily('mono')->placeholder(__('every item'))
                    ->description(fn (CustomerPriceRule $r) => $r->item?->name),
                TextColumn::make('min_base_quantity')->label(__('From qty'))->alignEnd()->formatStateUsing(fn ($state) => Format::quantity((string) $state)),
                TextColumn::make('price')->label(__('Price'))->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '—' : Format::money((int) $state)),
                TextColumn::make('discount_percent')->label(__('Discount (%)'))->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '—' : Format::quantity((string) $state)),
                Tanggal::make('effective_from')->label(__('Effective from'))->placeholder('—'),
                Tanggal::make('effective_until')->label(__('Effective until'))->placeholder('—'),
                TextColumn::make('reason')->label(__('Reason'))->limit(40)->toggleable(),
                self::activeColumn(),
            ])
            ->defaultSort('customer.name')
            ->filters([
                SelectFilter::make('customer_id')->label(__('fields.customer'))->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                self::activeFilter(),
            ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomerPriceRules::route('/')];
    }
}
