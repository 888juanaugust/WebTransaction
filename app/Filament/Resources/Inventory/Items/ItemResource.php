<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Items;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Company\DataStart;
use App\Domain\Inventory\StockQuery;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\ItemType;
use App\Domain\Shared\Format;
use App\Filament\Pages\Inventory\StockByWarehouse;
use App\Filament\Resources\Inventory\Items\Pages\CreateItem;
use App\Filament\Resources\Inventory\Items\Pages\EditItem;
use App\Filament\Resources\Inventory\Items\Pages\ListItems;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\NumberFields;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
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

/** The Items & Services screen: the standard's tabs, with units, prices per level and opening stock. */
class ItemResource extends MasterResource
{
    protected static ?string $model = Item::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $modelLabel = 'Item';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ItemsAndServices;
    }

    private static function money(string $name, string $label): TextInput
    {
        return MoneyInput::make($name)->label($label)->prefix(Format::symbol())->default(0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Item'))
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label(__('Item name'))->required()->maxLength(200)->columnSpan(2),
                    NumberFields::make(TransactionType::Item, __('Item code')),
                    Select::make('item_type')->label(__('Item type'))->options(ItemType::class)->default(ItemType::Inventory)->required()->native(false)->live(),
                    TextInput::make('upc_no')->label(__('UPC / barcode'))->maxLength(50),
                    TextInput::make('part_number')->label(__('Part number'))->maxLength(100)->disabled()->dehydrated(false),
                    TextInput::make('vehicle')->label(__('Vehicle'))->maxLength(150)->disabled()->dehydrated(false),
                    TextInput::make('product_type')->label(__('Product type'))->maxLength(100)->disabled()->dehydrated(false),
                    Select::make('unit1_id')->label(__('Base unit'))->relationship('unit1', 'name')->preload()->required()->native(false)
                        ->default(fn () => Unit::query()->where('name', 'PCS')->value('id')),
                    Select::make('brand_id')->label(__('Brand'))->relationship('brand', 'name')->preload()->searchable()->native(false),
                    Select::make('category_id')->label(__('Category'))->relationship('category', 'name')->preload()->searchable()->native(false)
                        ->default(fn () => ItemCategory::query()->where('is_default', true)->value('id')),
                    self::activeToggle()->inline(false),
                ]),
            Tabs::make('item')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('Sales / Purchasing'))->schema([
                        Grid::make(3)->schema([
                            TextInput::make('default_discount')->label(__('Default discount (%)'))->numeric()->minValue(0)->maxValue(100)->default(0),
                            self::money('sell_price', __('Selling price per base unit')),
                            TextInput::make('min_sell_qty')->label(__('Minimum sale quantity'))->numeric()->minValue(0)->default(0),
                            Toggle::make('use_wholesale_price')->label(__('Apply wholesale price / discount'))->inline(false),
                            Select::make('substitute_item_id')->label(__('Substitute item'))
                                ->relationship('substitute', 'name', fn ($query, ?Item $record) => $query->when($record, fn ($query) => $query->whereKeyNot($record->getKey())))
                                ->searchable()->native(false)->columnSpan(2),
                            Select::make('preferred_vendor_id')->label(__('Preferred vendor'))->relationship('preferredVendor', 'name', fn ($query) => $query->where('is_active', true))->searchable()->preload()->native(false),
                            Select::make('vendor_unit_id')->label(__('Purchase unit'))->relationship('vendorUnit', 'name')->preload()->native(false),
                            self::money('purchase_price', __('Purchase price'))->visible(fn (): bool => HakAkses::canSpecial(HakKhusus::SeeCost)),
                            TextInput::make('min_purchase_qty')->label(__('Minimum purchase quantity'))->numeric()->minValue(0)->default(0),
                            TextInput::make('min_stock')->label(__('Minimum stock'))->numeric()->minValue(0)->default(0)
                                ->helperText(__('Across all warehouses; a warehouse below may have its own.')),
                            Repeater::make('minimumStocks')
                                ->label(__('Minimum stock per warehouse'))
                                ->relationship()
                                ->table([TableColumn::make(__('Warehouse')), TableColumn::make(__('Minimum'))->alignment(Alignment::End)])
                                ->schema([
                                    Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => Warehouse::query()->where('is_system', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->required()->distinct()->native(false),
                                    TextInput::make('quantity')->label(__('Quantity'))->numeric()->minValue(0)->required()->default(0),
                                ])
                                ->defaultItems(0)->addActionLabel(__('Add warehouse'))->columnSpanFull()
                                ->visible(fn (Get $get) => ($get('item_type') instanceof ItemType ? $get('item_type') : ItemType::tryFrom((string) $get('item_type'))) === ItemType::Inventory),
                        ]),
                        Fieldset::make(__('Tax'))->columns(3)->schema([
                            TextInput::make('item_tax_code')->label(__('e-Tax goods code'))->maxLength(20)->placeholder(__('e.g. 110000')),
                            Select::make('tax1_id')->label(__('VAT'))->relationship('tax1', 'description', fn ($query) => $query->where('is_active', true))->preload()->native(false)
                                ->default(fn () => TaxCode::default()?->id),
                            Select::make('tax3_id')->label(__('Withholding tax'))->relationship('tax3', 'description', fn ($query) => $query->where('is_active', true))->preload()->native(false),
                        ]),
                    ]),
                    Tab::make(__('Units'))->schema([
                        Repeater::make('units')
                            ->label(__('Other units'))
                            ->helperText(__('The base unit counts as 1; a carton of 12 pieces has ratio 12.'))
                            ->relationship()
                            ->orderColumn('sort')
                            ->table([
                                TableColumn::make(__('Unit')),
                                TableColumn::make(__('Contains (base units)')),
                                TableColumn::make(__('Selling price')),
                            ])
                            ->schema([
                                Select::make('unit_id')->relationship('unit', 'name')->required()->native(false)->distinct(),
                                TextInput::make('ratio')->numeric()->minValue(0.000001)->required()->default(1),
                                self::money('sell_price', __('Selling price')),
                            ])
                            ->addActionLabel(__('Add unit'))
                            ->defaultItems(0),
                    ]),
                    Tab::make(__('Prices'))->schema([
                        Repeater::make('prices')
                            ->label(__('Selling price per price category'))
                            ->relationship()
                            ->orderColumn('sort')
                            ->table([
                                TableColumn::make(__('Price category')),
                                TableColumn::make(__('Unit')),
                                TableColumn::make(__('Price')),
                            ])
                            ->schema([
                                Select::make('price_category_id')->relationship('priceCategory', 'name')->required()->native(false),
                                Select::make('unit_id')->relationship('unit', 'name')->native(false)->placeholder(__('Base unit')),
                                self::money('price', __('Price')),
                            ])
                            ->addActionLabel(__('Add price'))
                            ->defaultItems(0),
                    ]),
                    Tab::make(__('Stock'))
                        ->visible(fn (Get $get) => ($get('item_type') ?? ItemType::Inventory->value) === ItemType::Inventory->value || $get('item_type') === ItemType::Inventory)
                        ->schema([
                            Repeater::make('openingStocks')
                                ->label(__('Opening stock at the data start date'))
                                ->helperText(__('Posted as the opening adjustment by the inventory module; after that, stock moves only through documents.'))
                                ->relationship()
                                ->orderColumn('sort')
                                // Opening stock is stock at a cost: entered by someone who may see cost (a disabled list is not saved).
                                ->disabled(fn (): bool => ! app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost))
                                ->table([
                                    TableColumn::make(__('Date')),
                                    TableColumn::make(__('Quantity')),
                                    TableColumn::make(__('Unit')),
                                    TableColumn::make(__('Unit cost')),
                                    TableColumn::make(__('Warehouse')),
                                ])
                                ->schema([
                                    DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(fn () => DataStart::openingDate()),
                                    TextInput::make('quantity')->label(__('Quantity'))->numeric()->required(),
                                    Select::make('unit_id')->relationship('unit', 'name')->native(false)->placeholder(__('Base unit')),
                                    TextInput::make('unit_cost')->label(__('Unit cost'))->numeric()->prefix(Format::symbol())->default(0)
                                        ->disabled(fn () => ! app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost))->dehydrated(),
                                    Select::make('warehouse_id')->label(__('Warehouse'))->relationship('warehouse', 'name', fn ($query) => $query->where('is_active', true))->required()->native(false),
                                ])
                                ->addActionLabel(__('Add opening stock'))
                                // The cost never reaches the page of someone who may not see it (the list is not saved for them).
                                ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost) ? $data : ['unit_cost' => null] + $data)
                                ->defaultItems(0),
                        ]),
                    Tab::make(__('Components'))
                        ->visible(fn (Get $get) => $get('item_type') === ItemType::Group->value || $get('item_type') === ItemType::Group)
                        ->schema([
                            Repeater::make('components')
                                ->label(__('Items in this group'))
                                ->relationship()
                                ->orderColumn('sort')
                                ->table([
                                    TableColumn::make(__('Item')),
                                    TableColumn::make(__('Quantity')),
                                    TableColumn::make(__('Unit')),
                                ])
                                ->schema([
                                    Select::make('item_id')->relationship('item', 'name', fn ($query) => $query->where('item_type', '!=', ItemType::Group->value))->searchable()->required()->native(false),
                                    TextInput::make('quantity')->label(__('Quantity'))->numeric()->required()->default(1),
                                    Select::make('unit_id')->relationship('unit', 'name')->native(false)->placeholder(__('Base unit')),
                                ])
                                ->addActionLabel(__('Add component'))
                                ->defaultItems(0),
                        ]),
                    Tab::make(__('Accounts'))->schema([
                        Grid::make(2)->schema([
                            Select::make('inventory_account_id')->label(__('Inventory'))->options(fn () => Account::options(AccountType::Inventory))->searchable()->native(false)->placeholder(__('The category\'s')),
                            Select::make('sales_account_id')->label(__('Sales'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false)->placeholder(__('The category\'s')),
                            Select::make('cogs_account_id')->label(__('Cost of goods sold'))->options(fn () => Account::options(AccountType::CostOfSales))->searchable()->native(false)->placeholder(__('The category\'s')),
                            Select::make('sales_return_account_id')->label(__('Sales returns'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false)->placeholder(__('The category\'s')),
                            Select::make('purchase_return_account_id')->label(__('Purchase returns'))->options(fn () => Account::options(AccountType::Inventory, AccountType::CostOfSales))->searchable()->native(false)->placeholder(__('The category\'s')),
                        ]),
                    ]),
                    Tab::make(__('Other'))->schema([
                        BranchFields::select(__('Used in branch'), defaulted: false),
                        Textarea::make('notes')->label(__('fields.memo'))->rows(2),
                        Grid::make(4)->schema([
                            TextInput::make('length_cm')->label(__('Length (cm)'))->numeric()->minValue(0),
                            TextInput::make('width_cm')->label(__('Width (cm)'))->numeric()->minValue(0),
                            TextInput::make('height_cm')->label(__('Height (cm)'))->numeric()->minValue(0),
                            TextInput::make('weight_gr')->label(__('Weight (g)'))->numeric()->minValue(0),
                        ]),
                    ]),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $seesCost = app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost);
        $onHand = fn () => once(fn () => StockQuery::onHandMap());
        // What sits in the warehouses this user may use.
        $mine = fn () => once(fn () => ItemCost::query()->whereIn('warehouse_id', Warehouse::query()->visibleTo(auth()->user())->select('id'))
            ->groupBy('item_id')->selectRaw('item_id, SUM(qty_on_hand) AS qty')->pluck('qty', 'item_id')->all());

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['unit1', 'brand', 'category']))
            ->columns([
                TextColumn::make('number')->label(__('Item code'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('item_type')->label(__('Type'))->badge()->color('gray'),
                TextColumn::make('unit1.name')->label(__('fields.unit')),
                TextColumn::make('name')->label(__('Item name'))->searchable()->sortable()->weight('medium')->wrap(),
                TextColumn::make('brand.name')->label(__('Brand'))->placeholder('—'),
                TextColumn::make('category.name')->label(__('Category'))->placeholder('—')->toggleable(),
                TextColumn::make('stock')->label(__('Available stock'))->state(fn (Item $r) => Format::quantity($onHand()[$r->id] ?? '0'))->alignEnd()
                    ->url(fn (Item $r) => StockByWarehouse::getUrl(['item' => $r->id])),
                TextColumn::make('my_stock')->label(__('In my warehouses'))->state(fn (Item $r) => Format::quantity((string) ($mine()[$r->id] ?? '0')))->alignEnd()->toggleable(),
                Rupiah::make('purchase_price')->label(__('Purchase price'))->visible($seesCost),
                Rupiah::make('sell_price')->label(__('Selling price')),
                TextColumn::make('min_stock')->label(__('Minimum stock'))->state(fn (Item $r) => Format::quantity($r->min_stock))->alignEnd(),
            ])
            ->defaultSort('number')
            ->filters([
                self::activeFilter(),
                SelectFilter::make('item_type')->label(__('Item type'))->options(ItemType::class)->multiple(),
                SelectFilter::make('category_id')->label(__('Category'))->relationship('category', 'name'),
                SelectFilter::make('brand_id')->label(__('Brand'))->relationship('brand', 'name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItems::route('/'),
            'create' => CreateItem::route('/create'),
            'edit' => EditItem::route('/{record}/edit'),
        ];
    }
}
