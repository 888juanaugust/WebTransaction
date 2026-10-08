<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashPayments;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\CashBank\CashPayments\Pages\CreateCashPayment;
use App\Filament\Resources\CashBank\CashPayments\Pages\EditCashPayment;
use App\Filament\Resources\CashBank\CashPayments\Pages\ListCashPayments;
use App\Filament\Resources\Company\MemorizedTransactions\MemorizedTransactionResource;
use App\Filament\Support\AccrualFields;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\GiroActions;
use App\Filament\Support\LineTaxFields;
use App\Filament\Support\LineTotals;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\TagFields;
use App\Models\CashBank\CashPayment;
use App\Models\Company\MemorizedTransaction;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Str;

/** Payments: money out of a cash or bank account to any accounts, one line each, a line may settle an expense accrual or a payroll entry; paid by giro it waits in giros payable until the giro clears. */
class CashPaymentResource extends ErpResource
{
    protected static ?string $model = CashPayment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $modelLabel = 'Payment';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Payments;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('bank_account_id')->label(__('Cash / Bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->required()->native(false),
                DatePicker::make('trans_date')->label(__('Date'))->required()->native(false)->default(today()),
                NumberFields::make(TransactionType::CashBankVoucher, __('Voucher No.')),
                Placeholder::make('amount_preview')->label(__('Amount'))->content(fn (Get $get) => Format::rupiah(LineTotals::sum($get('lines'), 'amount'))),
            ]),
            Tabs::make('payment')->tabs([
                Tab::make(__('Payment details'))->schema([
                    Action::make('pullAccruals')
                        ->label(__('Pull open accruals and payroll'))
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('gray')
                        ->schema([
                            CheckboxList::make('documents')
                                ->label(__('Open documents'))
                                ->options(fn () => AccrualFields::openFor()->map(fn (array $open) => $open['label'])->all())
                                ->required()
                                ->bulkToggleable(),
                        ])
                        ->action(function (Set $set, Get $get, array $data): void {
                            $rows = array_filter((array) $get('lines'), fn ($line) => filled($line['account_id'] ?? null));
                            $open = AccrualFields::openFor();
                            foreach ($data['documents'] ?? [] as $key) {
                                if (isset($open[$key])) {
                                    $rows[(string) Str::uuid()] = self::settlingLine($key, $open[$key]);
                                }
                            }
                            $set('lines', $rows);
                            Notification::make()->title(__(':count open document(s) pulled', ['count' => count($data['documents'] ?? [])]))->success()->send();
                        }),
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Settles')),
                            TableColumn::make(__('Account')),
                            TableColumn::make(__('Amount'))->alignment(Alignment::End),
                            ...LineTaxFields::columns(),
                            ...TagFields::columns(),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            Select::make('payable_key')->label(__('Settles'))
                                ->options(fn (Get $get) => AccrualFields::openFor($get('payable_key'))->map(fn (array $open) => $open['label'])->all())
                                ->placeholder(__('Nothing: an expense'))
                                ->searchable()->native(false)->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $open = $state ? AccrualFields::openFor($state)->get($state) : null;
                                    if ($open !== null) {
                                        $set('account_id', $open['account_id']);
                                        $set('amount', $open['balance']);
                                    }
                                }),
                            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options())->searchable()->required()->native(false)
                                ->disabled(fn (Get $get) => filled($get('payable_key')))->dehydrated(),
                            PricedDocumentForm::money('amount', __('Amount'))->required()->live(onBlur: true),
                            ...LineTaxFields::fields(fn (Get $get) => filled($get('payable_key'))),
                            ...TagFields::lineFields(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                        ])
                        ->minItems(1)->defaultItems(1)->live()
                        ->addActionLabel(__('Add line'))
                        ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => $data + ['payable_key' => AccrualFields::keyOf($data)])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => AccrualFields::split($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => AccrualFields::split($data)),
                ]),
                Tab::make(__('Other info'))->schema([
                    BranchFields::select(),
                    ...TagFields::header(),
                    LineTaxFields::inclusiveToggle(),
                    TextInput::make('cheque_no')->label(__('Cheque / giro No.'))->maxLength(40)->helperText(__('Filling this registers a giro that clears or bounces later.')),
                    DatePicker::make('cheque_date')->label(__('Giro due date'))->native(false),
                    Textarea::make('payee')->label(__('Payee'))->rows(2),
                    Textarea::make('description')->label(__('Notes'))->rows(3),
                ])->columns(2),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['bankAccount', 'giro']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Date')),
                TextColumn::make('bankAccount.name')->label(__('Cash / Bank')),
                TextColumn::make('cheque_no')->label(__('Cheque No.'))->placeholder('—'),
                TextColumn::make('description')->label(__('Notes'))->limit(40)->placeholder('—'),
                TextColumn::make('giro.status')->label(__('Giro'))->badge()->formatStateUsing(fn (string $state) => Format::code($state, 'giro'))->color(fn (string $state) => GiroActions::statusColor($state))->placeholder('—'),
                Rupiah::make('amount')->label(__('Amount')),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                DocumentListFilters::dateRange('cheque_date', __('Cheque date')),
                SelectFilter::make('bank_account_id')->label(__('Cash / Bank'))->options(fn () => Account::options(AccountType::CashBank)),
            ])
            ->recordActions([...ApprovalActions::make(), EditAction::make(), ...GiroActions::forRecord(), self::memorizeAction(), PrintAction::make()]);
    }

    /** @param  array{label: string, balance: int, account_id: int}  $open */
    public static function settlingLine(string $key, array $open): array
    {
        $doc = AccrualFields::resolve($key);

        return ['payable_key' => $key, 'account_id' => $open['account_id'], 'amount' => $open['balance'], 'memo' => $doc?->number];
    }

    /** Saves the voucher's accounts and amounts as a memorized transaction, used again from the create page. */
    public static function memorizeAction(): Action
    {
        return Action::make('memorize')
            ->label(__('Memorize'))
            ->icon('heroicon-m-bookmark')
            ->color('gray')
            // A template makes documents of this kind: making one takes the create right here and on Memorized Transactions.
            ->visible(fn (): bool => static::canCreate() && MemorizedTransactionResource::canCreate())
            ->schema([
                TextInput::make('name')->label(__('Template name'))->required()->maxLength(100)->default(fn ($record) => $record->description ?: $record->number),
            ])
            ->action(function (array $data, $record): void {
                MemorizedTransaction::query()->create([
                    'name' => $data['name'],
                    'transaction_type' => 'cash_bank_voucher_payment',
                    'template' => [
                        'bank_account_id' => $record->bank_account_id,
                        'payee' => $record->payee,
                        'description' => $record->description,
                        'lines' => $record->lines->map(fn ($line) => ['account_id' => $line->account_id, 'amount' => $line->amount, 'memo' => $line->memo])->values()->all(),
                    ],
                    'used_all_user' => true,
                    'created_by' => auth()->id(),
                ]);
                Notification::make()->title(__(':name memorized', ['name' => $data['name']]))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashPayments::route('/'),
            'create' => CreateCashPayment::route('/create'),
            'edit' => EditCashPayment::route('/{record}/edit'),
        ];
    }
}
