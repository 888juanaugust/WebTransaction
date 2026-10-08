<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\FiscalAssetCategories;

use App\Domain\Access\MenuKey;
use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\Shared\Format;
use App\Filament\Resources\FixedAssets\FiscalAssetCategories\Pages\ManageFiscalAssetCategories;
use App\Filament\Support\MasterResource;
use App\Models\FixedAssets\FiscalAssetCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Fiscal Asset Groups: the tax office's method, estimated life and rate for each group of assets. */
class FiscalAssetCategoryResource extends MasterResource
{
    protected static ?string $model = FiscalAssetCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $modelLabel = 'Fiscal asset group';

    public static function menuKey(): MenuKey
    {
        return MenuKey::FiscalAssetCategories;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('depreciation_method')->label(__('Depreciation method'))->options(DepreciationMethod::class)->default(DepreciationMethod::StraightLine)->required()->native(false),
            TextInput::make('useful_life_years')->label(__('Estimated life (years)'))->numeric()->integer()->minValue(0)->required(),
            TextInput::make('rate_percent')->label(__('Depreciation rate (%)'))->numeric()->step(0.01)->minValue(0)->maxValue(100)->suffix('%'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rate_percent')->label(__('Rate (%)'))->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state): string => Format::quantity($state, 2)),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('useful_life_years')->label(__('Estimated life (years)'))->alignEnd()->sortable(),
                TextColumn::make('depreciation_method')->label(__('Method'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => $state instanceof DepreciationMethod ? $state->getLabel() : $state),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('depreciation_method')->label(__('Depreciation method'))->options(DepreciationMethod::class),
            ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFiscalAssetCategories::route('/')];
    }
}
