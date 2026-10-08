<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Units;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Inventory\Units\Pages\ManageUnits;
use App\Filament\Support\MasterResource;
use App\Models\Inventory\Unit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitResource extends MasterResource
{
    protected static ?string $model = Unit::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $modelLabel = 'Unit';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Units;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('unit')->tabs([
                Tab::make(__('General'))->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(30)->unique(ignoreRecord: true),
                ]),
                Tab::make(__('Tax info'))->schema([
                    TextInput::make('unit_tax_code')->label(__('e-Tax unit code'))->placeholder(__('UM.0018'))->maxLength(20)
                        ->helperText(__('The unit code the tax office expects on the tax invoice export.')),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('unit_tax_code')->label(__('e-Tax unit code'))->placeholder('—'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUnits::route('/')];
    }
}
