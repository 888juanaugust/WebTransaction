<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesInvoices;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Sales\CreditCheck;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\Sales\SalesInvoices\Pages\EditSalesInvoice;
use App\Filament\Resources\Sales\SalesInvoices\Pages\ListSalesInvoices;
use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\DownPaymentDeductions;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\PullAction;
use App\Filament\Support\SalesLinesTab;
use App\Filament\Support\SettlementLineFields;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Sales Invoices: the bill to the customer, from deliveries, from orders or direct; charges; down payments deducted; the tax invoice serial. */
class SalesInvoiceResource extends ErpResource
{
    protected static ?string $model = SalesInvoice::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'Sales invoice';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesInvoices;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(CustomerFields::select(), TransactionType::SalesInvoice, __('Invoice No.')),
            Tabs::make('invoice')->tabs([
                SalesLinesTab::make(
                    before: [
                        PullAction::make(__('Pull from deliveries'), 'customer_id',
                            fn (Get $get) => Delivery::query()->where('customer_id', $get('customer_id'))->whereIn('status', ['pending', 'partial'])->orderByDesc('trans_date')->get(),
                            fn (int $id) => PricedDocumentForm::pulledLines(Delivery::query()->findOrFail($id)->lines()->with('item')->get(), 'delivery_line'),
                        ),
                        PullAction::make(__('Pull from orders'), 'customer_id',
                            fn (Get $get) => SalesOrder::query()->where('customer_id', $get('customer_id'))->where('approval_status', SalesOrder::APPROVED)->whereIn('status', ['pending', 'partial'])->orderByDesc('trans_date')->get(),
                            fn (int $id) => PricedDocumentForm::pulledLines(SalesOrder::query()->findOrFail($id)->lines()->with('item')->get(), 'sales_order_line'),
                        )->name('pullOrders'),
                    ],
                ),
                PricedDocumentForm::otherInfoTab([
                    CustomerFields::paymentTerm(),
                    TextInput::make('po_number')->label(__('fields.po_number'))->maxLength(60),
                    DatePicker::make('due_date')->label(__('Due date'))->native(false)->helperText(__('Blank: from the payment term.')),
                    TextInput::make('nsfp')->label(__('Tax invoice serial (NSFP)'))->maxLength(40)->helperText(__('Pasted back from the tax office after filing.')),
                ]),
                PricedDocumentForm::chargesTab(),
                Tab::make(__('Down payments'))->schema([
                    DownPaymentDeductions::repeater(SalesDownPayment::class, 'sales_down_payment_id', 'customer_id'),
                ]),
                Tab::make(__('Payment info'))->schema([
                    Placeholder::make('tax_invoice_mails')->label(__('Tax invoice emails'))
                        ->visible(fn (?SalesInvoice $record) => $record !== null && $record->taxInvoiceMails()->exists())
                        ->content(fn (?SalesInvoice $record) => new HtmlString($record?->taxInvoiceMails()->where('status', '!=', 'queued')->get()
                            ->map(fn ($mail) => e(Format::dateTime($mail->created_at).' · '.__('status.mail.'.$mail->status).' · '.$mail->recipient.' · '.$mail->serial.($mail->error ? ' · '.$mail->error : '')))
                            ->join('<br>') ?: e(__('Queued')))),
                    Placeholder::make('paid')->label(__('Paid'))->content(fn (?SalesInvoice $record) => $record ? __(':paid of :total · open :open', ['paid' => CurrencyFields::documentAmount($record, 'paid_amount'), 'total' => CurrencyFields::format(SettlementLineFields::total($record), $record->currency_id), 'open' => CurrencyFields::format(SettlementLineFields::open($record), $record->currency_id)]) : '—'),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable(),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('payment_status')->label(__('fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('status.payment.'.($state === 'partial' ? 'partially_paid' : $state)))
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'partial' => 'warning', default => 'gray'
                    }),
                TextColumn::make('age')->label(__('Age (days)'))->state(fn (SalesInvoice $r) => $r->payment_status === 'paid' ? '' : (string) $r->trans_date->diffInDays(today()))->alignEnd()
                    ->color(fn (SalesInvoice $r) => HakAkses::canSpecial(HakKhusus::SeeCreditData) && ($notice = app(CreditCheck::class)->noticeDays()) > 0 && $r->payment_status !== 'paid' && $r->trans_date->diffInDays(today()) > $notice ? 'danger' : null),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
                TextColumn::make('nsfp')->label(__('NSFP'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_printed')->label(__('fields.is_printed'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('customer_id')->label(__('fields.customer'))->relationship('customer', 'name')->searchable(),
                TernaryFilter::make('is_printed')->label(__('fields.is_printed')),
            ])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('receive')->label(__('Receive payment'))->icon('heroicon-m-banknotes')->color('primary')
                    ->visible(fn (SalesInvoice $record) => $record->payment_status !== 'paid' && SalesReceiptResource::canCreate())
                    ->url(fn (SalesInvoice $record) => SalesReceiptResource::getUrl('create', ['source' => 'sales_invoice:'.$record->id])),
                PrintAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesInvoices::route('/'),
            'create' => CreateSalesInvoice::route('/create'),
            'edit' => EditSalesInvoice::route('/{record}/edit'),
        ];
    }
}
