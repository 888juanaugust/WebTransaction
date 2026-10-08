<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameOrders;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Inventory\StockOpnameOrders\Pages\CreateStockOpnameOrder;
use App\Filament\Resources\Inventory\StockOpnameOrders\Pages\EditStockOpnameOrder;
use App\Filament\Resources\Inventory\StockOpnameOrders\Pages\ListStockOpnameOrders;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\NumberFields;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Stock Opname Orders: who counts what in which warehouse, from when. */
class StockOpnameOrderResource extends ErpResource
{
    protected static ?string $model = StockOpnameOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Stock opname order';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::StockOpnameOrders;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Order'))
                ->columns(3)
                ->schema([
                    DatePicker::make('trans_date')->label(__('Order date'))->required()->native(false)->default(today()),
                    NumberFields::make(TransactionType::StockOpnameOrder, __('Order No.')),
                    DatePicker::make('start_date')->label(__('Count starts'))->required()->native(false)->default(today()),
                    TextInput::make('person_charged')->label(__('Person in charge'))->required()->maxLength(100),
                    Select::make('users')->label(__('Counted by'))->relationship('users', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))->multiple()->preload()->required()->native(false),
                    Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => Warehouse::query()->visibleTo(auth()->user())->where('is_system', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->required()->native(false),
                    Textarea::make('description')->label(__('fields.description'))->rows(2)->columnSpanFull(),
                ]),
            Section::make(__('Items to count'))
                ->description(__('Leave a filter empty to count everything.'))
                ->columns(3)
                ->schema([
                    Select::make('itemCategories')->label(__('Item categories'))->relationship('itemCategories', 'name')->multiple()->preload()->native(false),
                    Select::make('vendors')->label(__('Preferred vendors'))->relationship('vendors', 'name')->multiple()->preload()->searchable()->native(false),
                    Select::make('brands')->label(__('Brands'))->relationship('brands', 'name')->multiple()->preload()->native(false),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('warehouse'))
            ->columns([
                Tanggal::make('trans_date')->label(__('Order date')),
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('start_date')->label(__('Count starts')),
                TextColumn::make('warehouse.name')->label(__('Warehouse')),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => Format::code($state, 'opname'))
                    ->color(fn (string $state) => match ($state) {
                        'counted' => 'success', 'closed' => 'gray', default => 'info'
                    }),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('person_charged')->label(__('Person in charge')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('status')->label(__('Status'))->options(['open' => __('Open'), 'counted' => __('Counted'), 'closed' => __('Closed')]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->hidden(fn (StockOpnameOrder $r) => $r->results()->exists())]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnameOrders::route('/'),
            'create' => CreateStockOpnameOrder::route('/create'),
            'edit' => EditStockOpnameOrder::route('/{record}/edit'),
        ];
    }
}
