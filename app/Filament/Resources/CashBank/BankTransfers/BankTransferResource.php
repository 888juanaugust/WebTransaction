<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\BankTransfers;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\CashBank\BankTransfers\Pages\CreateBankTransfer;
use App\Filament\Resources\CashBank\BankTransfers\Pages\EditBankTransfer;
use App\Filament\Resources\CashBank\BankTransfers\Pages\ListBankTransfers;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineTotals;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Models\CashBank\BankTransfer;
use App\Models\GeneralLedger\Account;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Bank Transfers: money moved between two cash/bank accounts, with transfer fees charged to either side. */
class BankTransferResource extends ErpResource
{
    protected static ?string $model = BankTransfer::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $modelLabel = 'Bank transfer';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::BankTransfers;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                DatePicker::make('trans_date')->label(__('Date'))->required()->native(false)->default(today()),
                NumberFields::make(TransactionType::BankTransfer, __('Transfer No.')),
                Select::make('from_bank_account_id')->label(__('From cash / bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->required()->native(false)->live(),
                PricedDocumentForm::money('amount', __('Amount transferred'))->required()->live(onBlur: true),
                Select::make('to_bank_account_id')->label(__('To cash / bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->required()->native(false)
                    ->different('from_bank_account_id')
                    ->validationMessages(['different' => 'Pick two different accounts.']),
                Textarea::make('description')->label(__('Notes'))->rows(2)->columnSpanFull(),
            ]),
            Tabs::make('transfer')->tabs([
                Tab::make(__('Transfer fees'))->schema([
                    Repeater::make('fees')->label(__('Fees'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Account')),
                            TableColumn::make(__('Charged to')),
                            TableColumn::make(__('Amount'))->alignment(Alignment::End),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options(AccountType::Expense, AccountType::OtherExpense))->searchable()->required()->native(false),
                            Select::make('charged_to')->options(['from' => __('The sending account'), 'to' => __('The receiving account')])->default('from')->required()->native(false),
                            PricedDocumentForm::money('amount', __('Amount'))->required()->live(onBlur: true),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                        ])
                        ->defaultItems(0)->live()
                        ->addActionLabel(__('Add fee')),
                    Placeholder::make('fees_total_preview')->label(__('Fees total'))->content(fn (Get $get) => Format::rupiah(LineTotals::sum($get('fees'), 'amount'))),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fromBankAccount', 'toBankAccount']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Date')),
                TextColumn::make('fromBankAccount.name')->label(__('From')),
                TextColumn::make('toBankAccount.name')->label(__('To')),
                TextColumn::make('description')->label(__('Notes'))->limit(40)->placeholder('—'),
                Rupiah::make('amount')->label(__('Amount')),
                Rupiah::make('fees_total')->label(__('Fees')),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('from_bank_account_id')->label(__('From'))->options(fn () => Account::options(AccountType::CashBank)),
                SelectFilter::make('to_bank_account_id')->label(__('To'))->options(fn () => Account::options(AccountType::CashBank)),
            ])
            ->recordActions([...ApprovalActions::make(), EditAction::make(), PrintAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankTransfers::route('/'),
            'create' => CreateBankTransfer::route('/create'),
            'edit' => EditBankTransfer::route('/{record}/edit'),
        ];
    }
}
