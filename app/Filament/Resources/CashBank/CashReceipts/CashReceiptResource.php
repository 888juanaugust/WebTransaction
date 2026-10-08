<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashReceipts;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\CashBank\CashReceipts\Pages\CreateCashReceipt;
use App\Filament\Resources\CashBank\CashReceipts\Pages\EditCashReceipt;
use App\Filament\Resources\CashBank\CashReceipts\Pages\ListCashReceipts;
use App\Filament\Resources\Company\MemorizedTransactions\MemorizedTransactionResource;
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
use App\Models\CashBank\CashReceipt;
use App\Models\Company\MemorizedTransaction;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Receipts: other money into a cash or bank account, credited to any accounts, one line each; received by giro it waits in giros receivable until the giro clears. */
class CashReceiptResource extends ErpResource
{
    protected static ?string $model = CashReceipt::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $modelLabel = 'Receipt';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Receipts;
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
            Tabs::make('receipt')->tabs([
                Tab::make(__('Receipt details'))->schema([
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
                            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options())->searchable()->required()->native(false),
                            PricedDocumentForm::money('amount', __('Amount'))->required()->live(onBlur: true),
                            ...LineTaxFields::fields(),
                            ...TagFields::lineFields(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                        ])
                        ->minItems(1)->defaultItems(1)->live()
                        ->addActionLabel(__('Add line')),
                ]),
                Tab::make(__('Other info'))->schema([
                    BranchFields::select(),
                    ...TagFields::header(),
                    LineTaxFields::inclusiveToggle(),
                    TextInput::make('cheque_no')->label(__('Cheque / giro No.'))->maxLength(40)->helperText(__('Filling this registers a giro received that clears or bounces later.')),
                    DatePicker::make('cheque_date')->label(__('Giro due date'))->native(false),
                    Textarea::make('payer')->label(__('Payer'))->rows(2),
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
                    'transaction_type' => 'cash_bank_voucher_receipt',
                    'template' => [
                        'bank_account_id' => $record->bank_account_id,
                        'payer' => $record->payer,
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
            'index' => ListCashReceipts::route('/'),
            'create' => CreateCashReceipt::route('/create'),
            'edit' => EditCashReceipt::route('/{record}/edit'),
        ];
    }
}
