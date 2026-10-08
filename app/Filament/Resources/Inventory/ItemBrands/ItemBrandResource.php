<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemBrands;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Inventory\ItemBrands\Pages\ManageItemBrands;
use App\Filament\Support\MasterResource;
use App\Models\Inventory\ItemBrand;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemBrandResource extends MasterResource
{
    protected static ?string $model = ItemBrand::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static ?string $modelLabel = 'Brand';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ItemBrands;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageItemBrands::route('/')];
    }
}
