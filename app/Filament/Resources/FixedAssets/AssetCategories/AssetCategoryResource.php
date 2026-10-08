<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetCategories;

use App\Domain\Access\MenuKey;
use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\Shared\Enums\AccountType;
use App\Filament\Resources\FixedAssets\AssetCategories\Pages\ManageAssetCategories;
use App\Filament\Support\MasterResource;
use App\Models\FixedAssets\AssetCategory;
use App\Models\GeneralLedger\Account;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Asset Categories: the accounts, method and useful life a new asset of the category starts with. */
class AssetCategoryResource extends MasterResource
{
    protected static ?string $model = AssetCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $modelLabel = 'Asset category';

    public static function menuKey(): MenuKey
    {
        return MenuKey::AssetCategories;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('asset_account_id')->label(__('Asset account'))->options(fn () => Account::options(AccountType::FixedAsset, AccountType::OtherCurrentAsset))->searchable()->required()->native(false),
            Select::make('accumulated_depreciation_account_id')->label(__('Accumulated depreciation account'))->options(fn () => Account::options(AccountType::AccumulatedDepreciation))->searchable()->required()->native(false),
            Select::make('depreciation_expense_account_id')->label(__('Depreciation expense account'))->options(fn () => Account::options(AccountType::Expense, AccountType::OtherExpense))->searchable()->required()->native(false),
            Select::make('depreciation_method')->label(__('Depreciation method'))->options(DepreciationMethod::class)->default(DepreciationMethod::StraightLine)->required()->native(false),
            TextInput::make('useful_life_months')->label(__('Useful life (months)'))->numeric()->integer()->minValue(0)->default(48)->required(),
            self::activeToggle(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('assetAccount'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('assetAccount.name')->label(__('Asset account'))->placeholder('—'),
                TextColumn::make('depreciation_method')->label(__('Method'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => $state instanceof DepreciationMethod ? $state->getLabel() : $state),
                TextColumn::make('useful_life_months')->label(__('Life (months)'))->alignEnd()->sortable(),
                self::activeColumn(),
            ])
            ->defaultSort('name')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssetCategories::route('/')];
    }
}
