<?php

declare(strict_types=1);

namespace App\Filament\Resources\Budgeting\BudgetTransfers;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Filament\Resources\Budgeting\BudgetTransfers\Pages\CreateBudgetTransfer;
use App\Filament\Resources\Budgeting\BudgetTransfers\Pages\EditBudgetTransfer;
use App\Filament\Resources\Budgeting\BudgetTransfers\Pages\ListBudgetTransfers;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\Months;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PricedDocumentForm;
use App\Models\Budgeting\BudgetTransfer;
use App\Models\GeneralLedger\Account;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Budget Transfers: budget moved from one account and month to another within the year; no journal, the budget lines follow. */
class BudgetTransferResource extends ErpResource
{
    protected static ?string $model = BudgetTransfer::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $modelLabel = 'Budget transfer';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::BudgetTransfers;
    }

    private static function accountOptions(): array
    {
        return Account::options(AccountType::Revenue, AccountType::CostOfSales, AccountType::Expense, AccountType::OtherIncome, AccountType::OtherExpense);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('year')->label(__('Year'))->numeric()->required()->minValue(2000)->maxValue(2100)->default(today()->year),
                Select::make('scope')->label(__('Type'))->options(['general' => __('General')])->default('general')->required()->native(false),
                NumberFields::make(TransactionType::BudgetTransfer, __('Transfer No.')),
                DatePicker::make('trans_date')->label(__('Date'))->required()->native(false)->default(today()),
            ]),
            Section::make(__('From budget'))->columns(2)->schema([
                Select::make('from_month')->label(__('Month'))->options(Months::options())->required()->native(false),
                Select::make('from_account_id')->label(__('Budget account'))->options(fn () => self::accountOptions())->searchable()->required()->native(false),
                PricedDocumentForm::money('amount', __('Amount transferred'))->required()->minValue(1),
            ]),
            Section::make(__('To budget'))->columns(2)->schema([
                Select::make('to_month')->label(__('Month'))->options(Months::options())->required()->native(false),
                Select::make('to_account_id')->label(__('Budget account'))->options(fn () => self::accountOptions())->searchable()->required()->native(false),
            ]),
            Tabs::make('transfer')->tabs([
                Tab::make(__('Notes'))->schema([
                    Textarea::make('description')->label(__('Notes'))->rows(2)->maxLength(255),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fromAccount', 'toAccount']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Date')),
                TextColumn::make('year')->label(__('Year'))->sortable(),
                TextColumn::make('fromAccount.name')->label(__('From account')),
                TextColumn::make('from_month')->label(__('From month'))->formatStateUsing(fn ($state): string => Months::name($state)),
                TextColumn::make('toAccount.name')->label(__('To account')),
                TextColumn::make('to_month')->label(__('To month'))->formatStateUsing(fn ($state): string => Months::name($state)),
                Rupiah::make('amount')->label(__('Amount')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgetTransfers::route('/'),
            'create' => CreateBudgetTransfer::route('/create'),
            'edit' => EditBudgetTransfer::route('/{record}/edit'),
        ];
    }
}
