<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemCategories;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Enums\AccountType;
use App\Filament\Resources\Inventory\ItemCategories\Pages\ManageItemCategories;
use App\Filament\Support\MasterResource;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\ItemCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemCategoryResource extends MasterResource
{
    protected static ?string $model = ItemCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $modelLabel = 'Item category';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ItemCategories;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('category')->tabs([
                Tab::make(__('General'))->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100),
                    Select::make('parent_id')
                        ->label(__('Sub-category of'))
                        ->relationship('parent', 'name', fn ($query, ?ItemCategory $record) => $query->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->orderBy('name'))
                        ->searchable()->preload()->native(false),
                    Toggle::make('is_default')->label(__('Default category'))->inline(false),
                ]),
                Tab::make(__('Accounts'))
                    ->schema([
                        Select::make('inventory_account_id')->label(__('Inventory'))->options(fn () => Account::options(AccountType::Inventory))->searchable()->native(false),
                        Select::make('sales_account_id')->label(__('Sales'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false),
                        Select::make('cogs_account_id')->label(__('Cost of goods sold'))->options(fn () => Account::options(AccountType::CostOfSales))->searchable()->native(false),
                        Select::make('sales_return_account_id')->label(__('Sales returns'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false),
                        Select::make('purchase_return_account_id')->label(__('Purchase returns'))->options(fn () => Account::options(AccountType::Inventory, AccountType::CostOfSales))->searchable()->native(false),
                    ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()
                    ->state(fn (ItemCategory $r) => $r->parent ? "{$r->parent->name} › {$r->name}" : $r->name),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageItemCategories::route('/')];
    }
}
