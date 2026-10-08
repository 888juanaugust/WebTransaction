<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\SalaryComponents;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Enums\AccountType;
use App\Filament\Resources\Company\SalaryComponents\Pages\ManageSalaryComponents;
use App\Filament\Support\MasterResource;
use App\Models\Company\SalaryComponent;
use App\Models\GeneralLedger\Account;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Salary Components: each salary or allowance kind and the expense account a payroll entry books it to. */
class SalaryComponentResource extends MasterResource
{
    protected static ?string $model = SalaryComponent::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'Salary component';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalaryComponents;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('fee_type')->label(__('Component kind'))->options(SalaryComponent::feeTypes())->required()->native(false)->default('salary'),
            Select::make('expense_account_id')->label(__('Expense account'))
                ->options(fn () => Account::options(AccountType::Expense, AccountType::OtherExpense, AccountType::OtherCurrentLiability))
                ->searchable()->required()->native(false),
            static::activeToggle(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('expenseAccount'))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('fee_type')->label(__('Component kind'))->formatStateUsing(fn ($state): string => SalaryComponent::feeTypes()[$state] ?? (string) $state)->sortable(),
                TextColumn::make('expenseAccount.name')->label(__('Expense account')),
                static::activeColumn(),
            ])
            ->defaultSort('name')
            ->filters([
                static::activeFilter(),
                SelectFilter::make('fee_type')->label(__('Component kind'))->options(SalaryComponent::feeTypes()),
            ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSalaryComponents::route('/')];
    }
}
