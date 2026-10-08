<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\InvoiceExchanges;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\InvoiceExchanges\Pages\CreateInvoiceExchange;
use App\Filament\Resources\Sales\InvoiceExchanges\Pages\EditInvoiceExchange;
use App\Filament\Resources\Sales\InvoiceExchanges\Pages\ListInvoiceExchanges;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\NumberFields;
use App\Models\Sales\InvoiceExchange;
use App\Models\Sales\SalesInvoice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Invoice Exchanges: the receipt for invoices handed to a customer, with the date they are collected and fall due. */
class InvoiceExchangeResource extends ErpResource
{
    protected static ?string $model = InvoiceExchange::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $modelLabel = 'Invoice exchange';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::InvoiceExchanges;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                CustomerFields::select(fillsTerms: false),
                DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today()),
                NumberFields::make(TransactionType::InvoiceExchange),
                DatePicker::make('collect_date')->label(__('Exchange date'))->required()->native(false)->default(today()),
                DatePicker::make('due_date')->label(__('Due date'))->required()->native(false)->default(fn () => today()->addDays(30)),
            ]),
            Tabs::make('exchange')->tabs([
                Tab::make(__('Invoices'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([TableColumn::make(__('Invoice')), TableColumn::make(__('Invoice date')), TableColumn::make(__('Due'))])
                        ->schema([
                            Select::make('sales_invoice_id')->label(__('Invoice'))
                                ->options(fn (Get $get) => SalesInvoice::query()->where('customer_id', $get('../../customer_id'))->where('payment_status', '!=', 'paid')->orderByDesc('trans_date')->get()
                                    ->mapWithKeys(fn ($i) => [$i->id => "{$i->number} · ".Format::rupiah($i->total)]))
                                ->required()->native(false)->live()->distinct(),
                            Placeholder::make('invoice_date')->label(__('Invoice date'))->hiddenLabel()->content(fn (Get $get) => ($i = SalesInvoice::query()->find($get('sales_invoice_id'))) ? Format::date($i->trans_date) : ''),
                            Placeholder::make('due')->label(__('Due'))->hiddenLabel()->content(fn (Get $get) => ($i = SalesInvoice::query()->find($get('sales_invoice_id'))) ? Format::date($i->due_date) : ''),
                        ])
                        ->minItems(1)->defaultItems(1)->addActionLabel(__('Add invoice')),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                Tanggal::make('collect_date')->label(__('Exchange date')),
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => $state === 'processed' ? 'Collected' : 'Pending')->color(fn (string $state) => $state === 'processed' ? 'success' : 'info'),
                Rupiah::make('total')->label(__('Invoice total')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), DocumentListFilters::dateRange('collect_date', __('Exchange date')), SelectFilter::make('customer_id')->label(__('fields.customer'))->relationship('customer', 'name')->searchable()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceExchanges::route('/'),
            'create' => CreateInvoiceExchange::route('/create'),
            'edit' => EditInvoiceExchange::route('/{record}/edit'),
        ];
    }
}
