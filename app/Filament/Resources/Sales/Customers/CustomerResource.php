<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Customers;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\TaxDocumentCode;
use App\Domain\Shared\Enums\WpType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Filament\Resources\Sales\Customers\Pages\ListCustomers;
use App\Filament\Support\AddressFields;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\NumberFields;
use App\Filament\Support\OpeningBalanceFields;
use App\Models\Company\Employee;
use App\Models\Company\PaymentTerm;
use App\Models\GeneralLedger\Account;
use App\Models\Sales\Customer;
use App\Models\Sales\CustomerCategory;
use App\Models\Sales\PriceCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** The Customer screen: every tab of the standard's form. */
class CustomerResource extends MasterResource
{
    protected static ?string $model = Customer::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'Customer';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Customers;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General'))
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(150)->columnSpan(2),
                    NumberFields::make(TransactionType::Customer, __('Customer ID')),
                    Select::make('category_id')->label(__('Category'))->relationship('category', 'name')->preload()->searchable()->native(false)
                        ->default(fn () => CustomerCategory::query()->where('is_default', true)->value('id')),
                    TextInput::make('work_phone')->label(__('Work phone'))->tel()->maxLength(30),
                    TextInput::make('mobile_phone')->label(__('Mobile'))->tel()->maxLength(30),
                    TextInput::make('whatsapp')->label(__('WhatsApp'))->tel()->maxLength(30),
                    TextInput::make('email')->label(__('Email'))->email()->maxLength(150),
                    TextInput::make('fax')->label(__('Fax'))->maxLength(30),
                    TextInput::make('website')->label(__('Website'))->maxLength(150),
                    BranchFields::select(__('Used in branch'), defaulted: false),
                    self::activeToggle()->inline(false),
                ]),
            Tabs::make('customer')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('Billing address'))->schema([AddressFields::make('bill', __('Billing address'))]),
                    Tab::make(__('Contacts'))->schema([
                        Repeater::make('contacts')->label(__('Contacts'))
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort')
                            ->table([
                                TableColumn::make(__('Full name')),
                                TableColumn::make(__('Position')),
                                TableColumn::make(__('Email')),
                                TableColumn::make(__('Mobile')),
                                TableColumn::make(__('Birthday')),
                            ])
                            ->schema([
                                TextInput::make('name')->required()->maxLength(150),
                                TextInput::make('position')->maxLength(100),
                                TextInput::make('email')->email()->maxLength(150),
                                TextInput::make('mobile_phone')->tel()->maxLength(30),
                                DatePicker::make('birth_date')->native(false)->maxDate(today()),
                            ])
                            ->addActionLabel(__('Add contact'))
                            ->defaultItems(0),
                    ]),
                    Tab::make(__('Shipping'))->schema([
                        Toggle::make('ship_same_as_bill')->label(__('Same as the billing address'))->default(true)->live(),
                        AddressFields::make('ship', __('Shipping address'))->visible(fn (Get $get) => ! $get('ship_same_as_bill')),
                        Repeater::make('addresses')
                            ->label(__('Other delivery addresses'))
                            ->relationship()
                            ->orderColumn('sort')
                            ->simple(Textarea::make('address')->rows(2)->required())
                            ->addActionLabel(__('Add address'))
                            ->defaultItems(0),
                    ]),
                    Tab::make(__('Sales'))->schema([
                        Grid::make(2)->schema([
                            Select::make('customer_type_id')->label(__('Customer type'))->relationship('customerType', 'name', fn ($query) => $query->where('is_active', true))->preload()->native(false)
                                ->helperText(__('The type\'s tier, payment term and credit age limit are copied onto the customer when it is set.')),
                            Select::make('price_category_id')->label(__('Price category'))->relationship('priceCategory', 'name')->preload()->native(false)
                                ->default(fn () => PriceCategory::query()->where('is_default', true)->value('id')),
                            Select::make('discount_price_category_id')->label(__('Discount category'))->relationship('discountPriceCategory', 'name')->preload()->native(false)
                                ->helperText(__('The price category whose discount adjustments apply; blank means the price category above.')),
                            Select::make('salesman_id')->label(__('Default salesperson'))->options(fn () => Employee::query()->salesmen()->orderBy('name')->pluck('name', 'id'))->searchable()->native(false),
                            Select::make('payment_term_id')->label(__('fields.payment_term'))->relationship('paymentTerm', 'name', fn ($query) => $query->where('is_active', true))->preload()->native(false)
                                ->default(fn () => PaymentTerm::default()?->id),
                            TextInput::make('default_sales_disc')->label(__('Default discount (%)'))->numeric()->minValue(0)->maxValue(100)->default(0),
                            CurrencyFields::select(__('New sales documents open in this currency.')),
                            TextInput::make('default_invoice_desc')->label(__('Default invoice description'))->maxLength(255),
                        ]),
                        Fieldset::make(__('Accounts'))
                            ->columns(2)
                            ->schema([
                                Select::make('receivable_account_id')->label(__('Receivable'))->options(fn () => Account::options(AccountType::AccountsReceivable))->searchable()->native(false),
                                Select::make('down_payment_account_id')->label(__('Down payments'))->options(fn () => Account::options(AccountType::OtherCurrentLiability))->searchable()->native(false),
                                Select::make('sales_account_id')->label(__('Sales'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false),
                                Select::make('item_discount_account_id')->label(__('Item discounts'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false),
                                Select::make('cogs_account_id')->label(__('Cost of goods sold'))->options(fn () => Account::options(AccountType::CostOfSales))->searchable()->native(false),
                                Select::make('sales_return_account_id')->label(__('Sales returns'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false),
                                Select::make('sales_discount_account_id')->label(__('Sales discounts'))->options(fn () => Account::options(AccountType::Revenue))->searchable()->native(false),
                            ]),
                    ]),
                    Tab::make(__('Tax'))->schema([
                        Toggle::make('default_inc_tax')->label(__('Invoice totals include tax by default'))
                            ->default(fn () => app(Preferensi::class)->isOn(PreferensiKey::NewCustomerInclusiveTax)),
                        Grid::make(2)->schema([
                            Select::make('wp_type')->label(__('Tax ID type'))->options(WpType::class)->native(false),
                            TextInput::make('wp_number')->label(__('Tax ID number'))->maxLength(30),
                            TextInput::make('wp_name')->label(__('Taxpayer name'))->maxLength(150),
                            TextInput::make('nitku')->label(__('Business location ID (NITKU)'))->maxLength(30),
                            TextInput::make('country_tax_code')->label(__('Country code'))->maxLength(5)->default('IDN'),
                            Select::make('document_code')->label(__('Transaction type'))->options(TaxDocumentCode::options(TaxDocumentCode::forCustomers()))->native(false),
                            TextInput::make('tax_invoice_email')->label(__('Tax invoices go to'))->email()->maxLength(150)->placeholder(__('The customer\'s email')),
                        ]),
                        Toggle::make('tax_same_as_bill')->label(__('Tax address is the billing address'))->default(true)->live(),
                        AddressFields::make('tax', __('Tax address'))->visible(fn (Get $get) => ! $get('tax_same_as_bill')),
                    ]),
                    Tab::make(__('Opening balance'))->schema([
                        OpeningBalanceFields::repeater(__('Add open invoice')),
                    ]),
                    Tab::make(__('Other'))->schema([
                        Fieldset::make(__('Credit limit'))->visible(fn () => HakAkses::canSpecial(HakKhusus::SeeCreditData))->schema([
                            Radio::make('credit_limit_mode')->label(__('Credit limit'))
                                ->hiddenLabel()
                                ->options(['per_customer' => __('Per customer'), 'parent' => __('Shared with a parent customer')])
                                ->default('per_customer')
                                ->live(),
                            Select::make('parent_customer_id')->label(__('Parent customer'))
                                ->relationship('parentCustomer', 'name', fn ($query, ?Customer $record) => $query->when($record, fn ($query) => $query->whereKeyNot($record->getKey())))
                                ->searchable()->preload()->native(false)
                                ->visible(fn (Get $get) => $get('credit_limit_mode') === 'parent'),
                            Grid::make(2)->schema([
                                Toggle::make('credit_limit_age_enabled')->label(__('Block when an invoice is older than'))->live()->inline(false),
                                TextInput::make('credit_limit_age_days')->label(__('days'))->numeric()->integer()->minValue(0)->default(0)->visible(fn (Get $get) => $get('credit_limit_age_enabled')),
                                Toggle::make('credit_limit_amount_enabled')->label(__('Block when receivables and open orders exceed'))->live()->inline(false),
                                MoneyInput::make('credit_limit_amount')->label(__('amount'))->prefix(Format::symbol())->default(0)->visible(fn (Get $get) => $get('credit_limit_amount_enabled')),
                            ])->visible(fn (Get $get) => $get('credit_limit_mode') === 'per_customer'),
                        ]),
                        Select::make('default_warehouse_id')->label(__('Default warehouse'))->relationship('defaultWarehouse', 'name', fn ($query) => $query->where('is_active', true))->preload()->native(false),
                        Textarea::make('notes')->label(__('fields.memo'))->rows(3),
                    ]),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'priceCategory', 'discountCategory', 'branch', 'paymentTerm', 'contacts']))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('primary_contact')->label(__('Primary contact'))->state(fn (Customer $r) => $r->contacts->first()?->name)->placeholder('—'),
                TextColumn::make('number')->label(__('Customer ID'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('category.name')->label(__('Category'))->placeholder('—'),
                TextColumn::make('customerType.name')->label(__('Customer type'))->placeholder('—')->toggleable(),
                TextColumn::make('priceCategory.name')->label(__('Price category'))->placeholder('—'),
                TextColumn::make('discountCategory.name')->label(__('Discount category'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tax_address')->label(__('Tax address'))->state(fn (Customer $r) => $r->taxAddress())->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('branch.name')->label(__('fields.branch'))->placeholder('—'),
                TextColumn::make('bill_address')->label(__('Address'))->state(fn (Customer $r) => $r->billAddress())->limit(40)->placeholder('—'),
                TextColumn::make('paymentTerm.name')->label(__('fields.payment_term'))->placeholder('—'),
                Rupiah::make('credit_limit_amount')->label(__('Credit limit'))->toggleable(isToggledHiddenByDefault: true)->visible(fn () => HakAkses::canSpecial(HakKhusus::SeeCreditData)),
            ])
            ->defaultSort('name')
            ->filters([
                self::activeFilter(),
                SelectFilter::make('category_id')->label(__('Category'))->relationship('category', 'name'),
                SelectFilter::make('branch_id')->label(__('fields.branch'))->relationship('branch', 'name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
