<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\PriceCategories;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Sales\PriceCategories\Pages\ManagePriceCategories;
use App\Filament\Support\MasterResource;
use App\Models\Sales\PriceCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Price levels: a customer holds one, an item carries a price per level. */
class PriceCategoryResource extends MasterResource
{
    protected static ?string $model = PriceCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $modelLabel = 'Price category';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PriceCategories;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Category name'))->required()->maxLength(100)->unique(ignoreRecord: true),
            Textarea::make('notes')->label(__('fields.memo'))->rows(3),
            Toggle::make('is_default')->label(__('Default level'))->inline(false),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('notes')->label(__('fields.memo'))->limit(60)->placeholder('—'),
                TextColumn::make('name')->label(__('Category name'))->searchable()->sortable(),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePriceCategories::route('/')];
    }
}
