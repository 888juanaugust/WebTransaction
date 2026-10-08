<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\ExpenseAccruals;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages\CreateExpenseAccrual;
use App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages\EditExpenseAccrual;
use App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages\ListExpenseAccruals;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineTaxFields;
use App\Filament\Support\LineTotals;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PayAction;
use App\Filament\Support\TagFields;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\ExpenseAccrual;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Expense Accruals: expenses booked to any accounts against a payable, paid later from Cash & Bank. */
class ExpenseAccrualResource extends ErpResource
{
    protected static ?string $model = ExpenseAccrual::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $modelLabel = 'Expense accrual';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ExpenseAccruals;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    Select::make('payable_account_id')->label(__('Expense payable'))->options(fn () => Account::options(AccountType::AccountsPayable, AccountType::OtherCurrentLiability))->searchable()->required()->native(false)
                        ->default(fn () => Account::query()->where('no', '2230')->value('id')),
                    DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today())->live(onBlur: true),
                    NumberFields::make(TransactionType::ExpenseAccrual, __('Expense No.')),
                ]),
            Tabs::make('accrual')->tabs([
                Tab::make(__('Expense lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Account')),
                            TableColumn::make(__('Amount'))->alignment(Alignment::End),
                            ...LineTaxFields::columns(),
                            ...TagFields::columns(),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options(AccountType::Expense, AccountType::OtherExpense, AccountType::CostOfSales, AccountType::OtherCurrentAsset, AccountType::FixedAsset))->searchable()->required()->native(false),
                            MoneyInput::make('amount')->label(__('Amount'))->required()->minValue(1)->live(onBlur: true),
                            ...LineTaxFields::fields(),
                            ...TagFields::lineFields(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                        ])
                        ->live()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel(__('Add line')),
                    Placeholder::make('total')->label(__('Total'))
                        ->hiddenLabel()
                        ->content(fn (Get $get): string => 'Total '.Format::rupiah(LineTotals::sum($get('lines'), 'amount'))),
                ]),
                Tab::make(__('Other info'))->schema([
                    DatePicker::make('due_date')->label(__('Due date'))->required()->native(false)
                        ->default(fn () => today()->addDays(30)),
                    BranchFields::select(),
                    ...TagFields::header(),
                    LineTaxFields::inclusiveToggle(),
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                Tanggal::make('due_date')->label(__('Due date')),
                Rupiah::make('total')->label(__('fields.total')),
                Rupiah::make('paid_amount')->label(__('fields.paid')),
                TextColumn::make('payment_status')->label(__('fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('status.payment.'.match ($state) {
                        'partial' => 'partially_paid', default => $state
                    }))
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'partial' => 'warning', default => 'gray'
                    }),
                TextColumn::make('description')->label(__('fields.description'))->limit(50)->placeholder('—'),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                SelectFilter::make('payment_status')->label(__('fields.status'))->options(['unpaid' => __('Unpaid'), 'partial' => __('Partially paid'), 'paid' => __('Paid')]),
                Filter::make('trans_date')
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false),
                        DatePicker::make('until')->label(__('Until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('trans_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('trans_date', '<=', $d))),
            ])
            ->recordActions([...ApprovalActions::make(), EditAction::make(), PayAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseAccruals::route('/'),
            'create' => CreateExpenseAccrual::route('/create'),
            'edit' => EditExpenseAccrual::route('/{record}/edit'),
        ];
    }
}
