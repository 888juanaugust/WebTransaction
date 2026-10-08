<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesDownPayments;

use App\Domain\Access\MenuKey;
use App\Domain\Currency\Currencies;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesDownPayments\Pages\CreateSalesDownPayment;
use App\Filament\Resources\Sales\SalesDownPayments\Pages\EditSalesDownPayment;
use App\Filament\Resources\Sales\SalesDownPayments\Pages\ListSalesDownPayments;
use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\NumberFields;
use App\Filament\Support\TagFields;
use App\Models\Company\TaxCode;
use App\Models\Sales\SalesDownPayment;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Sales Down Payments: an invoice for money up front with its own VAT; a receivable, deducted on the sales invoice. */
class SalesDownPaymentResource extends ErpResource
{
    protected static ?string $model = SalesDownPayment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'Sales down payment';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesDownPayments;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                CustomerFields::select(),
                DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today())->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get) => Currencies::isForeign($get('currency_id')) ? CurrencyFields::fillRates($set, $get, $get('currency_id')) : null),
                NumberFields::make(TransactionType::SalesInvoice, __('Invoice No.')),
                ...CurrencyFields::header(),
            ]),
            Tabs::make('down-payment')->tabs([
                Tab::make(__('Down payment'))->columns(2)->schema([
                    MoneyInput::inCurrency('amount', fn (Get $get) => CurrencyFields::decimals($get('currency_id')))->label(__('Down payment'))->default(0)->required()
                        ->rule(fn (): Closure => fn (string $attribute, mixed $value, Closure $fail) => CurrencyFields::isPositive($value) ? null : $fail(__('Enter an amount above zero.')))
                        ->prefix(fn (Get $get) => CurrencyFields::symbol($get('currency_id'))),
                    TextInput::make('po_number')->label(__('fields.po_number'))->maxLength(60),
                    Select::make('tax_code_id')->label(__('fields.tax_code'))->options(fn () => TaxCode::query()->where('is_active', true)->pluck('description', 'id'))->default(fn () => TaxCode::default()?->id)->native(false),
                    Toggle::make('taxable')->label(__('fields.taxable'))->default(true)->live(),
                    Toggle::make('inclusive_tax')->label(__('fields.inclusive_tax'))->default(false),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    BranchFields::select(),
                    ...TagFields::header(),
                    CustomerFields::paymentTerm(),
                    Textarea::make('to_address')->label(__('Address'))->rows(2),
                    Textarea::make('description')->label(__('fields.description'))->rows(2),
                ]),
                Tab::make(__('Payment info'))->schema([
                    Placeholder::make('paid')->label(__('Paid'))->content(fn (?SalesDownPayment $record) => $record ? __(':part of :whole', ['part' => CurrencyFields::documentAmount($record, 'paid_amount'), 'whole' => CurrencyFields::documentAmount($record, 'total')]) : '—'),
                    Placeholder::make('used')->label(__('Deducted on invoices'))->content(fn (?SalesDownPayment $record) => $record ? CurrencyFields::documentAmount($record, 'used_amount') : '—'),
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
                TextColumn::make('age')->label(__('Age (days)'))->state(fn (SalesDownPayment $r) => $r->payment_status === 'paid' ? '' : (string) $r->trans_date->diffInDays(today()))->alignEnd(),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('customer_id')->label(__('fields.customer'))->relationship('customer', 'name')->searchable(), TernaryFilter::make('is_printed')->label(__('fields.is_printed'))])
            ->recordActions([
                EditAction::make(),
                Action::make('receive')->label(__('Receive payment'))->icon('heroicon-m-banknotes')->color('primary')
                    ->visible(fn (SalesDownPayment $record) => $record->payment_status !== 'paid' && SalesReceiptResource::canCreate())
                    ->url(fn (SalesDownPayment $record) => SalesReceiptResource::getUrl('create', ['source' => 'sales_down_payment:'.$record->id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesDownPayments::route('/'),
            'create' => CreateSalesDownPayment::route('/create'),
            'edit' => EditSalesDownPayment::route('/{record}/edit'),
        ];
    }
}
