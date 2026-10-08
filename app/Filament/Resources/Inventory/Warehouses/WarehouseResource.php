<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Warehouses;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Inventory\Warehouses\Pages\ManageWarehouses;
use App\Filament\Support\BranchFields;
use App\Filament\Support\MasterResource;
use App\Models\Inventory\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehouseResource extends MasterResource
{
    protected static ?string $model = Warehouse::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'Warehouse';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Warehouses;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('warehouse')->tabs([
                Tab::make(__('General'))->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
                    Textarea::make('description')->label(__('Description'))->rows(2),
                    TextInput::make('pic')->label(__('Person in charge'))->maxLength(100),
                    BranchFields::select(defaulted: false),
                    Toggle::make('scrap_warehouse')->label(__('Use as the warehouse for damaged goods'))->inline(false),
                    Toggle::make('is_default')->label(__('Default warehouse'))->inline(false),
                    self::activeToggle()->inline(false),
                ]),
                Tab::make(__('Other info'))->schema([
                    Textarea::make('address')->label(__('Address'))->rows(3),
                ]),
                self::usersTab(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => self::visibleToCurrentUser($query->with('users')->where('is_system', false)))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('address')->label(__('Address'))->limit(60)->placeholder('—'),
                TextColumn::make('branch.name')->label(__('fields.branch'))->placeholder('—'),
                self::usersColumn(),
                IconColumn::make('scrap_warehouse')->label(__('Damaged goods'))->boolean(),
                self::activeColumn(),
            ])
            ->defaultSort('name')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()->hidden(fn (Warehouse $r) => $r->is_default)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWarehouses::route('/')];
    }
}
