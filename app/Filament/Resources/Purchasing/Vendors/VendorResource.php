<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\Vendors;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\TaxDocumentCode;
use App\Domain\Shared\Enums\WpType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\Vendors\Pages\CreateVendor;
use App\Filament\Resources\Purchasing\Vendors\Pages\EditVendor;
use App\Filament\Resources\Purchasing\Vendors\Pages\ListVendors;
use App\Filament\Support\AddressFields;
use App\Filament\Support\BranchFields;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\MasterResource;
use App\Filament\Support\NumberFields;
use App\Filament\Support\OpeningBalanceFields;
use App\Models\GeneralLedger\Account;
use App\Models\Purchasing\Vendor;
use App\Models\Purchasing\VendorCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

/** The Vendor screen: every tab of the standard's form. */
class VendorResource extends MasterResource
{
    protected static ?string $model = Vendor::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $modelLabel = 'Vendor';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Vendors;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General'))
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(150)->columnSpan(2),
                    NumberFields::make(TransactionType::Vendor, __('Vendor ID')),
                    Select::make('category_id')->label(__('Category'))->relationship('category', 'name')->preload()->searchable()->native(false)
                        ->default(fn () => VendorCategory::query()->where('is_default', true)->value('id')),
                    Select::make('vendor_type_id')->label(__('Vendor type'))->relationship('vendorType', 'name')->preload()->native(false),
                    BranchFields::select(__('Used in branch'))->required(),
                    TextInput::make('work_phone')->label(__('Work phone'))->tel()->maxLength(30),
                    TextInput::make('mobile_phone')->label(__('Mobile'))->tel()->maxLength(30),
                    TextInput::make('whatsapp')->label(__('WhatsApp'))->tel()->maxLength(30),
                    TextInput::make('email')->label(__('Email'))->email()->maxLength(150),
                    TextInput::make('fax')->label(__('Fax'))->maxLength(30),
                    TextInput::make('website')->label(__('Website'))->maxLength(150),
                    Toggle::make('service_seller')->label(__('Individual service provider (subject to income tax Art. 21)'))->inline(false)->columnSpan(2),
                    self::activeToggle()->inline(false),
                ]),
            Tabs::make('vendor')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('Address'))->schema([AddressFields::make('bill', __('Payment address'))]),
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
                            ])
                            ->schema([
                                TextInput::make('name')->required()->maxLength(150),
                                TextInput::make('position')->maxLength(100),
                                TextInput::make('email')->email()->maxLength(150),
                                TextInput::make('mobile_phone')->tel()->maxLength(30),
                            ])
                            ->addActionLabel(__('Add contact'))
                            ->defaultItems(0),
                    ]),
                    Tab::make(__('Purchasing'))->schema([
                        Grid::make(2)->schema([
                            TextInput::make('default_purchase_disc')->label(__('Default discount (%)'))->numeric()->minValue(0)->maxValue(100)->default(0),
                            Select::make('payment_term_id')->label(__('fields.payment_term'))->relationship('paymentTerm', 'name', fn ($query) => $query->where('is_active', true))->preload()->native(false),
                            CurrencyFields::select(__('New purchase documents open in this currency.')),
                            Textarea::make('default_invoice_desc')->label(__('Default invoice description'))->rows(2)->columnSpanFull(),
                            Select::make('payable_account_id')->label(__('Payable account'))->options(fn () => Account::options(AccountType::AccountsPayable))->searchable()->native(false),
                            Select::make('down_payment_account_id')->label(__('Down payment account'))->options(fn () => Account::options(AccountType::OtherCurrentAsset))->searchable()->native(false),
                        ]),
                        Repeater::make('bankAccounts')
                            ->label(__('Bank accounts'))
                            ->relationship()
                            ->orderColumn('sort')
                            ->table([
                                TableColumn::make(__('Bank account number')),
                                TableColumn::make(__('Account holder')),
                                TableColumn::make(__('Bank')),
                            ])
                            ->schema([
                                TextInput::make('bank_account')->required()->maxLength(50),
                                TextInput::make('bank_account_name')->maxLength(150),
                                Select::make('bank_id')->relationship('bank', 'name')->native(false)->searchable()->preload(),
                            ])
                            ->addActionLabel(__('Add bank account'))
                            ->defaultItems(0),
                    ]),
                    Tab::make(__('Tax'))->schema([
                        Toggle::make('default_inc_tax')->label(__('Invoice totals include tax by default'))->default(true),
                        Grid::make(2)->schema([
                            Select::make('wp_type')->label(__('Tax ID type'))->options(WpType::class)->native(false),
                            TextInput::make('wp_number')->label(__('Tax ID number'))->maxLength(30),
                            TextInput::make('wp_name')->label(__('Taxpayer name'))->maxLength(150),
                            TextInput::make('nitku')->label(__('Business location ID (NITKU)'))->maxLength(30),
                            Select::make('document_code')->label(__('Transaction type'))->options(TaxDocumentCode::options(TaxDocumentCode::forVendors()))->native(false),
                        ]),
                        Toggle::make('tax_same_as_bill')->label(__('Tax address is the payment address'))->default(true)->live(),
                        AddressFields::make('tax', __('Tax address'))->visible(fn (Get $get) => ! $get('tax_same_as_bill')),
                    ]),
                    Tab::make(__('Opening balance'))->schema([
                        OpeningBalanceFields::repeater(__('Add open bill')),
                    ]),
                    Tab::make(__('Other'))->schema([
                        Toggle::make('use_bill_number')->label(__('The vendor puts its own invoice number on bills'))->inline(false),
                        Textarea::make('notes')->label(__('fields.memo'))->rows(3),
                    ]),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'vendorType', 'branch']))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('number')->label(__('Vendor ID'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('category.name')->label(__('Category'))->placeholder('—'),
                TextColumn::make('vendorType.name')->label(__('Type'))->placeholder('—'),
                TextColumn::make('branch.name')->label(__('fields.branch'))->placeholder('—'),
                TextColumn::make('balance')->label(__('Balance'))->state(fn () => Format::rupiah(0))->alignEnd()
                    ->tooltip(__('Open payables arrive with the purchasing module.')),
            ])
            ->defaultSort('name')
            ->filters([
                self::activeFilter(),
                SelectFilter::make('category_id')->label(__('Category'))->relationship('category', 'name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
