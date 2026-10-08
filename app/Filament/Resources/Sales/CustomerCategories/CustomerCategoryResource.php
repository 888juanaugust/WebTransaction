<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\CustomerCategories;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Sales\CustomerCategories\Pages\ManageCustomerCategories;
use App\Filament\Support\MasterResource;
use App\Models\Sales\CustomerCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerCategoryResource extends MasterResource
{
    protected static ?string $model = CustomerCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'Customer category';

    public static function menuKey(): MenuKey
    {
        return MenuKey::CustomerCategories;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Category name'))->required()->maxLength(100),
            Select::make('parent_id')
                ->label(__('Sub-category of'))
                ->relationship('parent', 'name', fn ($query, ?CustomerCategory $record) => $query->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->orderBy('name'))
                ->searchable()
                ->preload()
                ->native(false),
            Toggle::make('is_default')->label(__('Default category'))->inline(false),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')->label(__('Category name'))->searchable()->sortable()
                    ->state(fn (CustomerCategory $r) => $r->parent ? "{$r->parent->name} › {$r->name}" : $r->name),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomerCategories::route('/')];
    }
}
