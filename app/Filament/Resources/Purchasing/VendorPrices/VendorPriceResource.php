<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorPrices;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\VendorPrices\Pages\CreateVendorPrice;
use App\Filament\Resources\Purchasing\VendorPrices\Pages\EditVendorPrice;
use App\Filament\Resources\Purchasing\VendorPrices\Pages\ListVendorPrices;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Filament\Support\VendorFields;
use App\Models\Purchasing\VendorPrice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Vendor Prices: a vendor's prices per item from a date, the default on purchase orders. */
class VendorPriceResource extends ErpResource
{
    protected static ?string $model = VendorPrice::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'Vendor price list';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::VendorPrices;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                VendorFields::select(fillsTerms: false),
                DatePicker::make('trans_date')->label(__('Effective from'))->required()->native(false)->default(today()),
                NumberFields::make(TransactionType::VendorPrice),
                Toggle::make('has_end_date')->label(__('Set an end date'))->live()->dehydrated(false)
                    ->afterStateHydrated(fn (Toggle $component, ?VendorPrice $record) => $component->state($record?->end_date !== null)),
                DatePicker::make('end_date')->label(__('Ends on'))->native(false)->visible(fn (Get $get) => (bool) $get('has_end_date')),
            ]),
            Tabs::make('prices')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([TableColumn::make(__('Item')), TableColumn::make(__('Unit')), TableColumn::make(__('New price'))->alignment(Alignment::End)])
                        ->schema([
                            LineItemFields::item(groups: false),
                            LineItemFields::unit(),
                            TextInput::make('price')->label(__('Price'))->numeric()->required()->prefix(Format::symbol()),
                        ])
                        ->minItems(1)->defaultItems(1)->addActionLabel(__('Add item')),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vendor'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Effective from')),
                TextColumn::make('vendor.name')->label(__('fields.vendor'))->searchable(),
                TextColumn::make('description')->label(__('fields.description'))->limit(50)->placeholder('—'),
                Tanggal::make('end_date')->label(__('Ends on'))->placeholder('—'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('vendor_id')->label(__('fields.vendor'))->relationship('vendor', 'name')->searchable()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorPrices::route('/'),
            'create' => CreateVendorPrice::route('/create'),
            'edit' => EditVendorPrice::route('/{record}/edit'),
        ];
    }
}
