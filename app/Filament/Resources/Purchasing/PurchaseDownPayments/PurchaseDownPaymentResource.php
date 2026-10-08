<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseDownPayments;

use App\Domain\Access\MenuKey;
use App\Domain\Currency\Currencies;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PurchaseDownPayments\Pages\CreatePurchaseDownPayment;
use App\Filament\Resources\Purchasing\PurchaseDownPayments\Pages\EditPurchaseDownPayment;
use App\Filament\Resources\Purchasing\PurchaseDownPayments\Pages\ListPurchaseDownPayments;
use App\Filament\Resources\Purchasing\PurchasePayments\PurchasePaymentResource;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\NumberFields;
use App\Filament\Support\TagFields;
use App\Filament\Support\VendorFields;
use App\Models\Company\TaxCode;
use App\Models\Purchasing\PurchaseDownPayment;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Filament\Tables\Table;

/** Purchase Down Payments: money promised to a vendor ahead of the invoice; a payable, later deducted on the invoice. */
class PurchaseDownPaymentResource extends ErpResource
{
    protected static ?string $model = PurchaseDownPayment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'Purchase down payment';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PurchaseDownPayments;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                VendorFields::select(),
                DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today())->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get) => Currencies::isForeign($get('currency_id')) ? CurrencyFields::fillRates($set, $get, $get('currency_id')) : null),
                NumberFields::make(TransactionType::PurchaseInvoice, __('Form No.')),
                ...CurrencyFields::header(),
            ]),
            Tabs::make('down-payment')->tabs([
                Tab::make(__('Down payment'))->columns(2)->schema([
                    MoneyInput::inCurrency('amount', fn (Get $get) => CurrencyFields::decimals($get('currency_id')))->label(__('Down payment'))->default(0)->required()
                        ->rule(fn (): Closure => fn (string $attribute, mixed $value, Closure $fail) => CurrencyFields::isPositive($value) ? null : $fail(__('Enter an amount above zero.')))
                        ->prefix(fn (Get $get) => CurrencyFields::symbol($get('currency_id'))),
                    Select::make('tax_code_id')->label(__('fields.tax_code'))->options(fn () => TaxCode::query()->where('is_active', true)->pluck('description', 'id'))->default(fn () => TaxCode::default()?->id)->native(false),
                    Toggle::make('taxable')->label(__('fields.taxable'))->default(true)->live(),
                    Toggle::make('inclusive_tax')->label(__('fields.inclusive_tax'))->default(false),
                    VendorFields::paymentTerm(),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    BranchFields::select(),
                    ...TagFields::header(),
                    VendorFields::bankAccount(),
                    Textarea::make('to_address')->label(__('Address'))->rows(2),
                    Textarea::make('description')->label(__('fields.description'))->rows(2),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vendor'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('vendor.name')->label(__('fields.vendor'))->searchable(),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('payment_status')->label(__('fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('status.payment.'.($state === 'partial' ? 'partially_paid' : $state)))
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'partial' => 'warning', default => 'gray'
                    }),
                TextColumn::make('age')->label(__('Age (days)'))->state(fn (PurchaseDownPayment $r) => $r->payment_status === 'paid' ? '' : (string) $r->trans_date->diffInDays(today()))->alignEnd(),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('vendor_id')->label(__('fields.vendor'))->relationship('vendor', 'name')->searchable(), SelectFilter::make('payment_status')->label(__('Payment'))->options(['unpaid' => __('Unpaid'), 'partial' => __('Partially paid'), 'paid' => __('Paid')])])
            ->recordActions([
                EditAction::make(),
                Action::make('pay')->label(__('Pay'))->icon('heroicon-m-banknotes')->color('primary')
                    ->visible(fn (PurchaseDownPayment $record) => $record->payment_status !== 'paid' && PurchasePaymentResource::canCreate())
                    ->url(fn (PurchaseDownPayment $record) => PurchasePaymentResource::getUrl('create', ['source' => 'purchase_down_payment:'.$record->id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseDownPayments::route('/'),
            'create' => CreatePurchaseDownPayment::route('/create'),
            'edit' => EditPurchaseDownPayment::route('/{record}/edit'),
        ];
    }
}
