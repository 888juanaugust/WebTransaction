<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PaymentOrders;

use App\Domain\Access\MenuKey;
use App\Domain\Documents\PaymentMethod;
use App\Domain\Numbering\TransactionType;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\PaymentOrders\Pages\CreatePaymentOrder;
use App\Filament\Resources\Purchasing\PaymentOrders\Pages\EditPaymentOrder;
use App\Filament\Resources\Purchasing\PaymentOrders\Pages\ListPaymentOrders;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PayableFields;
use App\Filament\Support\PricedDocumentForm;
use App\Models\GeneralLedger\Account;
use App\Models\Purchasing\PaymentOrder;
use App\Models\Purchasing\PurchaseInvoice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Validation\ValidationException;

/** Payment Orders: a batch of vendor invoices to pay by a date; Vendor Transfers turns it into payments. */
class PaymentOrderResource extends ErpResource
{
    protected static ?string $model = PaymentOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'Payment order';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PaymentOrders;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                DatePicker::make('trans_date')->label(__('Transfer deadline'))->required()->native(false)->default(today()),
                NumberFields::make(TransactionType::PaymentOrder, __('Voucher No.')),
                Select::make('payment_method')->label(__('Payment method'))->options([PaymentMethod::BankTransfer->value => __('Bank transfer'), PaymentMethod::VirtualAccount->value => __('Virtual account'), PaymentMethod::Cheque->value => __('Cheque / giro')])->default('bank_transfer')->required()->native(false),
                Select::make('bank_account_id')->label(__('Pay from bank'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->native(false),
            ]),
            Tabs::make('order')->tabs([
                Tab::make(__('Invoices'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Invoice')),
                            TableColumn::make(__('Vendor')),
                            TableColumn::make(__('Invoice date')),
                            TableColumn::make(__('Invoice total'))->alignment(Alignment::End),
                            TableColumn::make(__('Open balance'))->alignment(Alignment::End),
                            TableColumn::make(__('Pay'))->alignment(Alignment::End),
                            TableColumn::make(__('Discount'))->alignment(Alignment::End),
                        ])
                        ->schema([
                            Select::make('payable_key')->label(__('Invoice'))
                                ->options(fn () => PurchaseInvoice::query()->where('payment_status', '!=', 'paid')->with('vendor')->orderBy('due_date')->limit(200)->get()
                                    ->mapWithKeys(fn ($i) => ['purchase_invoice:'.$i->id => $i->number.' · '.$i->vendor->name.' · '.__('due :date', ['date' => Format::date($i->due_date)]).' · '.__('open').' '.Format::rupiah(app(SettlementService::class)->balance($i))]))
                                ->getOptionLabelUsing(fn ($value) => $value && ($doc = PayableFields::resolve($value)) ? $doc->number : null)
                                ->searchable()->required()->native(false)->live()
                                ->afterStateUpdated(function (Set $set, $state): void {
                                    $doc = $state ? PayableFields::resolve($state) : null;
                                    $set('vendor_id', $doc?->vendor_id);
                                    $set('amount', $doc ? app(SettlementService::class)->balance($doc) : 0);
                                }),
                            Placeholder::make('vendor')->label(__('fields.vendor'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('payable_key')) && ($doc = PayableFields::resolve($key)) ? $doc->vendor->name : ''),
                            Placeholder::make('invoice_date')->label(__('Invoice date'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('payable_key')) && ($doc = PayableFields::resolve($key)) ? Format::date($doc->trans_date) : ''),
                            Placeholder::make('invoice_total')->label(__('Invoice total'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('payable_key')) && ($doc = PayableFields::resolve($key)) ? Format::number((int) $doc->total) : ''),
                            Placeholder::make('open')->label(__('Open balance'))->hiddenLabel()->content(fn (Get $get) => ($key = $get('payable_key')) && ($doc = PayableFields::resolve($key)) ? Format::number(app(SettlementService::class)->balance($doc)) : ''),
                            PricedDocumentForm::money('amount', __('Pay'))->required()->minValue(1),
                            PricedDocumentForm::money('discount', __('Discount')),
                            Hidden::make('vendor_id'),
                            Hidden::make('payable_type'),
                            Hidden::make('payable_id'),
                        ])
                        ->minItems(1)->defaultItems(1)
                        ->addActionLabel(__('Add invoice'))
                        ->disabled(fn (?PaymentOrder $record) => $record?->status === 'processed')
                        ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => $data + ['payable_key' => ($data['payable_type'] ?? '').':'.($data['payable_id'] ?? '')])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::splitKey($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::splitKey($data)),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    private static function splitKey(array $data): array
    {
        [$type, $id] = array_pad(explode(':', (string) ($data['payable_key'] ?? ''), 2), 2, null);
        if (! in_array($type, PayableFields::TYPES, true) || ! ctype_digit((string) $id)) {
            throw ValidationException::withMessages(['data.lines' => __('Pick the document each line settles.')]);
        }
        $data['payable_type'] = $type;
        $data['payable_id'] = (int) $id;
        $data['vendor_id'] = $data['vendor_id'] ?: PayableFields::resolve((string) ($data['payable_key'] ?? ''))?->vendor_id;
        unset($data['payable_key']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('bankAccount'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Transfer deadline')),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('bankAccount.name')->label(__('Bank'))->placeholder('—'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', default => 'info'
                    }),
                Rupiah::make('total')->label(__('fields.total')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('status')->label(__('Status'))->options(['pending' => __('Pending'), 'partial' => __('Partial'), 'processed' => __('Processed')])])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentOrders::route('/'),
            'create' => CreatePaymentOrder::route('/create'),
            'edit' => EditPaymentOrder::route('/{record}/edit'),
        ];
    }
}
