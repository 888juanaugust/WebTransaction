<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReceipts;

use App\Domain\Access\MenuKey;
use App\Domain\Currency\Currencies;
use App\Domain\Documents\PaymentMethod;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesReceipts\Pages\CreateSalesReceipt;
use App\Filament\Resources\Sales\SalesReceipts\Pages\EditSalesReceipt;
use App\Filament\Resources\Sales\SalesReceipts\Pages\ListSalesReceipts;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\GiroActions;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PrintAction;
use App\Filament\Support\ReceivableFields;
use App\Filament\Support\SettlementLineFields;
use App\Filament\Support\TagFields;
use App\Models\GeneralLedger\Account;
use App\Models\Sales\SalesReceipt;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Sales Receipts: money in from a customer against open invoices and down payments, credit notes with "use credit", a discount or write-off per line. */
class SalesReceiptResource extends ErpResource
{
    protected static ?string $model = SalesReceipt::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'Sales receipt';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesReceipts;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                CustomerFields::select(fillsTerms: false, label: __('Received from')),
                Select::make('bank_account_id')->label(__('Bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->required()->native(false)->live()
                    ->afterStateUpdated(fn (Set $set, Get $get, $state) => CurrencyFields::forBank($set, $get, $state)),
                Select::make('payment_method')->label(__('Payment method'))->options(PaymentMethod::class)->default(PaymentMethod::BankTransfer)->required()->native(false)->live(),
                DatePicker::make('trans_date')->label(__('Payment date'))->required()->native(false)->default(today())->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get) => Currencies::isForeign($get('currency_id')) ? CurrencyFields::fillRates($set, $get, $get('currency_id')) : null),
                NumberFields::make(TransactionType::CashBankVoucher, __('Voucher No.')),
                Placeholder::make('amount_preview')->label(__('Amount received'))->content(fn (Get $get) => SettlementLineFields::sum($get('lines'), $get('currency_id'))),
                ...CurrencyFields::header(taxRate: false),
                Toggle::make('use_credit')->label(__('Use credit notes'))->live()->inline(false),
                TextInput::make('cheque_no')->label(__('Cheque / giro No.'))->maxLength(40)->visible(fn (Get $get) => $get('payment_method') === PaymentMethod::Cheque->value || $get('payment_method') === PaymentMethod::Cheque),
                DatePicker::make('cheque_date')->label(__('Cheque date'))->native(false)->visible(fn (Get $get) => $get('payment_method') === PaymentMethod::Cheque->value || $get('payment_method') === PaymentMethod::Cheque),
            ]),
            Tabs::make('receipt')->tabs([
                Tab::make(__('Invoices'))->schema([
                    Action::make('pullOpen')
                        ->label(__('Pull every open document'))
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (Get $get) => (bool) $get('customer_id'))
                        ->action(function (Set $set, Get $get): void {
                            $rows = [];
                            foreach (ReceivableFields::openFor((int) $get('customer_id'), (bool) $get('use_credit'), $get('currency_id')) as $key => $open) {
                                $rows[(string) Str::uuid()] = ['receivable_key' => $key, ...SettlementLineFields::proposal($open['model'], $get('trans_date'))];
                            }
                            $set('lines', $rows);
                            Notification::make()->title(__(':count open document(s) pulled', ['count' => count($rows)]))->success()->send();
                        }),
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Invoice')),
                            TableColumn::make(__('Invoice date')),
                            TableColumn::make(__('Invoice total'))->alignment(Alignment::End),
                            TableColumn::make(__('Open balance'))->alignment(Alignment::End),
                            TableColumn::make(__('Pay'))->alignment(Alignment::End),
                            TableColumn::make(__('Discount'))->alignment(Alignment::End),
                            TableColumn::make(__('Discount account')),
                        ])
                        ->schema([
                            Select::make('receivable_key')
                                ->options(fn (Get $get) => ReceivableFields::openFor((int) $get('../../customer_id'), (bool) $get('../../use_credit'), $get('../../currency_id'))->map(fn ($o) => $o['label'])->all())
                                ->getOptionLabelUsing(fn ($value) => $value && ($doc = ReceivableFields::resolve($value)) ? $doc->number : null)
                                ->required()->native(false)->live()
                                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                    // Paid within the term's discount days, the early-payment discount is proposed.
                                    $doc = $state ? ReceivableFields::resolve($state) : null;
                                    $proposal = $doc ? SettlementLineFields::proposal($doc, $get('../../trans_date')) : ['amount' => 0, 'discount' => 0];
                                    $set('amount', $proposal['amount']);
                                    $set('discount', $proposal['discount']);
                                }),
                            Placeholder::make('invoice_date')->label(__('Invoice date'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('receivable_key')) && ($doc = ReceivableFields::resolve($key)) ? Format::date($doc->trans_date) : ''),
                            Placeholder::make('invoice_total')->label(__('Invoice total'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('receivable_key')) && ($doc = ReceivableFields::resolve($key)) ? CurrencyFields::number(SettlementLineFields::total($doc), $doc->currency_id) : ''),
                            Placeholder::make('open')->label(__('Open balance'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('receivable_key')) && ($doc = ReceivableFields::resolve($key)) ? CurrencyFields::number(SettlementLineFields::open($doc), $doc->currency_id) : ''),
                            SettlementLineFields::amount('amount', __('Pay'))->required()->live(onBlur: true)
                                ->rule(fn (Get $get) => SettlementLineFields::notNegativeUnlessCredit(fn () => ($key = $get('receivable_key')) ? ReceivableFields::resolve($key) : null)),
                            SettlementLineFields::amount('discount', __('Discount'))->live(onBlur: true),
                            Select::make('discount_account_id')->options(fn () => Account::options(AccountType::Revenue, AccountType::OtherExpense, AccountType::Expense))->native(false)->placeholder(__('Sales Discounts')),
                            Hidden::make('receivable_type'),
                            Hidden::make('receivable_id'),
                        ])
                        ->minItems(1)->defaultItems(0)->live()
                        ->addActionLabel(__('Add document'))
                        ->mutateRelationshipDataBeforeFillUsing(fn (array $data, Get $get) => SettlementLineFields::fromForeign($data, $get('currency_id')) + ['receivable_key' => ($data['receivable_type'] ?? '').':'.($data['receivable_id'] ?? '')])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get) => SettlementLineFields::toForeign(self::splitKey($data), $get('currency_id')))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get) => SettlementLineFields::toForeign(self::splitKey($data), $get('currency_id'))),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    BranchFields::select(),
                    ...TagFields::header(),
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    private static function splitKey(array $data): array
    {
        [$type, $id] = array_pad(explode(':', (string) ($data['receivable_key'] ?? ''), 2), 2, null);
        if (! in_array($type, ReceivableFields::TYPES, true) || ! ctype_digit((string) $id)) {
            throw ValidationException::withMessages(['data.lines' => __('Pick the document each line settles.')]);
        }
        $data['receivable_type'] = $type;
        $data['receivable_id'] = (int) $id;
        unset($data['receivable_key']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'bankAccount', 'giro']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('cheque_no')->label(__('Cheque No.'))->placeholder('—')->toggleable(),
                Tanggal::make('cheque_date')->label(__('Cheque date'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable(),
                TextColumn::make('bankAccount.name')->label(__('Bank')),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                IconColumn::make('use_credit')->label(__('Credit used'))->boolean(),
                TextColumn::make('giro.status')->label(__('Giro'))->badge()->formatStateUsing(fn (string $state) => Format::code($state, 'giro'))->color(fn (string $state) => GiroActions::statusColor($state))->placeholder('—'),
                Rupiah::make('amount')->label(__('Amount received')),
                ...InCurrency::make('fc_amount'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                DocumentListFilters::dateRange('cheque_date', __('Cheque date')),
                SelectFilter::make('payment_method')->label(__('Method'))->options(PaymentMethod::class),
                SelectFilter::make('bank_account_id')->label(__('Bank'))->options(fn () => Account::options(AccountType::CashBank)),
                SelectFilter::make('customer_id')->label(__('Received from'))->relationship('customer', 'name')->searchable(),
            ])
            ->recordActions([EditAction::make(), ...GiroActions::forRecord(), PrintAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesReceipts::route('/'),
            'create' => CreateSalesReceipt::route('/create'),
            'edit' => EditSalesReceipt::route('/{record}/edit'),
        ];
    }
}
