<?php

declare(strict_types=1);

namespace App\Filament\Resources\Budgeting\Budgets;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Budgeting\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Budgeting\Budgets\Pages\EditBudget;
use App\Filament\Resources\Budgeting\Budgets\Pages\ListBudgets;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineTotals;
use App\Filament\Support\Months;
use App\Filament\Support\PricedDocumentForm;
use App\Models\Budgeting\Budget;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Budgets: one month's planned amount per income and expense account, read back by the Budget Monitor. */
class BudgetResource extends ErpResource
{
    protected static ?string $model = Budget::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?string $modelLabel = 'Budget';

    protected static ?string $recordTitleAttribute = 'year';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Budgets;
    }

    /** The accounts a budget is set for: the profit-and-loss side of the chart. */
    public static function accountOptions(): array
    {
        return Account::options(AccountType::Revenue, AccountType::CostOfSales, AccountType::Expense, AccountType::OtherIncome, AccountType::OtherExpense);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('month')->label(__('Month'))->options(Months::options())->required()->native(false)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('year', $get('year'))->where('scope', $get('scope')))
                    ->validationMessages(['unique' => 'A budget for that month exists.']),
                TextInput::make('year')->label(__('Year'))->numeric()->required()->minValue(2000)->maxValue(2100)->default(today()->year),
                Select::make('scope')->label(__('Type'))->options(['general' => __('General')])->default('general')->required()->native(false),
            ]),
            Tabs::make('budget')->tabs([
                Tab::make(__('Budget lines'))->schema([
                    Action::make('pullAccounts')
                        ->label(__('Pull every income and expense account'))
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (Get $get): bool => array_filter((array) $get('lines'), fn ($line) => ! empty($line['account_id'])) === [])
                        ->action(function (Set $set): void {
                            $rows = Account::query()->active()
                                ->ofType(AccountType::Revenue, AccountType::CostOfSales, AccountType::Expense, AccountType::OtherIncome, AccountType::OtherExpense)
                                ->orderBy('no')
                                ->get()
                                ->map(fn (Account $account): array => ['account_id' => $account->id, 'amount' => 0])
                                ->all();
                            $set('lines', DocumentPages::keyedRows($rows));
                            Notification::make()->title(__(':count account(s) pulled', ['count' => count($rows)]))->success()->send();
                        }),
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Account (income / expense)')),
                            TableColumn::make(__('Code')),
                            TableColumn::make(__('Amount'))->alignment(Alignment::End),
                        ])
                        ->schema([
                            Select::make('account_id')->label(__('Account'))->options(fn () => self::accountOptions())->searchable()->required()->native(false)->live()
                                ->distinct()
                                ->validationMessages(['distinct' => 'That account is already on the budget.']),
                            Placeholder::make('code')->label(__('Code'))->hiddenLabel()
                                ->content(fn (Get $get): string => $get('account_id') ? (string) (Account::query()->find($get('account_id'))?->no ?? '—') : '—'),
                            PricedDocumentForm::money('amount', __('Budget'))->required()->live(onBlur: true),
                        ])
                        ->defaultItems(1)
                        ->live()
                        ->addActionLabel(__('Add account')),
                    Placeholder::make('total')->label(__('Total budget'))->content(fn (Get $get): string => Format::rupiah(LineTotals::sum($get('lines'), 'amount'))),
                ]),
                Tab::make(__('Notes'))->schema([
                    Textarea::make('notes')->label(__('Notes'))->rows(3),
                    TextInput::make('analyst_name')->label(__('Analyst'))->maxLength(120),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSum('lines', 'amount'))
            ->columns([
                TextColumn::make('year')->label(__('Year'))->sortable(),
                TextColumn::make('month')->label(__('Month'))->formatStateUsing(fn ($state): string => Months::name($state))->sortable(),
                TextColumn::make('scope')->label(__('Type'))->formatStateUsing(fn ($state): string => Format::code((string) $state, 'budget_scope')),
                TextColumn::make('analyst_name')->label(__('Analyst'))->placeholder('—'),
                TextColumn::make('notes')->label(__('Notes'))->limit(40)->placeholder('—'),
                Rupiah::make('lines_sum_amount')->label(__('Total')),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('year')->orderByDesc('month'))
            ->filters([
                SelectFilter::make('scope')->label(__('Type'))->options(['general' => __('General')]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgets::route('/'),
            'create' => CreateBudget::route('/create'),
            'edit' => EditBudget::route('/{record}/edit'),
        ];
    }
}
