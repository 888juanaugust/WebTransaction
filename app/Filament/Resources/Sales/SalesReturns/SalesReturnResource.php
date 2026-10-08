<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReturns;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesReturns\Pages\CreateSalesReturn;
use App\Filament\Resources\Sales\SalesReturns\Pages\EditSalesReturn;
use App\Filament\Resources\Sales\SalesReturns\Pages\ListSalesReturns;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\PullAction;
use App\Filament\Support\SalesLinesTab;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Sales Returns: goods back from the customer, from an invoice, a delivery, a down payment or none; a credit note applied in a receipt. */
class SalesReturnResource extends ErpResource
{
    protected static ?string $model = SalesReturn::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $modelLabel = 'Sales return';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesReturns;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(CustomerFields::select(), TransactionType::SalesReturn, __('Return No.'), [
                Select::make('return_type')->label(__('Return from'))->options(['invoice' => __('Invoice'), 'delivery' => __('Delivery'), 'none' => __('No invoice'), 'down_payment' => __('Down payment')])->default('invoice')->required()->native(false)->live()
                    ->afterStateUpdated(fn (Set $set) => $set('source_key', null)),
                Select::make('source_key')->label(__('Document'))
                    ->options(function (Get $get) {
                        $customer = (int) $get('customer_id');
                        if (! $customer) {
                            return [];
                        }

                        return match ($get('return_type')) {
                            'invoice' => SalesInvoice::query()->where('customer_id', $customer)->orderByDesc('trans_date')->limit(50)->get()->mapWithKeys(fn ($d) => ['sales_invoice:'.$d->id => $d->number.' · '.Format::date($d->trans_date)])->all(),
                            'delivery' => Delivery::query()->where('customer_id', $customer)->orderByDesc('trans_date')->limit(50)->get()->mapWithKeys(fn ($d) => ['delivery:'.$d->id => $d->number.' · '.Format::date($d->trans_date)])->all(),
                            default => [],
                        };
                    })
                    ->native(false)->live()
                    ->visible(fn (Get $get) => in_array($get('return_type'), ['invoice', 'delivery'], true))
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (Select $component, ?SalesReturn $record) => $component->state($record?->source_type ? "{$record->source_type}:{$record->source_id}" : null)),
                Hidden::make('source_type')->dehydrated(),
                Hidden::make('source_id')->dehydrated(),
            ]),
            Tabs::make('return')->tabs([
                SalesLinesTab::make(
                    before: [PullAction::make(__('Pull the lines of the document'), 'customer_id', fn () => [], fn () => [])->action(function (Set $set, Get $get): void {
                        $key = (string) $get('source_key');
                        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
                        $doc = match ($type) {
                            'sales_invoice' => SalesInvoice::query()->find((int) $id), 'delivery' => Delivery::query()->find((int) $id), default => null
                        };
                        if ($doc === null) {
                            return;
                        }
                        $rows = [];
                        foreach ($doc->lines()->with('item')->get() as $line) {
                            $rows[(string) Str::uuid()] = ['item_id' => $line->item_id, 'quantity' => (string) $line->quantity, 'unit_id' => $line->unit_id, 'base_quantity' => (string) $line->base_quantity,
                                'unit_price' => (string) $line->unit_price, 'discount_percent' => (string) $line->discount_percent, 'tax_code_id' => $line->tax_code_id, 'warehouse_id' => $line->warehouse_id, 'salesman_id' => $line->salesman_id, 'memo' => $line->memo];
                        }
                        $set('lines', $rows);
                        $set('source_type', $type);
                        $set('source_id', (int) $id);
                        $set('taxable', $doc->taxable);
                        $set('inclusive_tax', $doc->inclusive_tax);
                    })],
                ),
                PricedDocumentForm::otherInfoTab(shipping: false),
                PricedDocumentForm::chargesTab(),
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
                TextColumn::make('return_type')->label(__('Return from'))->badge()->color('gray')->formatStateUsing(fn (string $state) => Format::code($state, 'return_type')),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('payment_status')->label(__('Credit used'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'paid' => __('Used'), 'partial' => __('Partly used'), default => __('Open')
                    })
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'partial' => 'warning', default => 'gray'
                    }),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('customer_id')->label(__('fields.customer'))->relationship('customer', 'name')->searchable(),
                SelectFilter::make('return_type')->label(__('Return from'))->options(['invoice' => __('Invoice'), 'delivery' => __('Delivery'), 'none' => __('No invoice'), 'down_payment' => __('Down payment')]),
                TernaryFilter::make('is_printed')->label(__('fields.is_printed')),
            ])
            ->recordActions([...ApprovalActions::make(), EditAction::make(), PrintAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesReturns::route('/'),
            'create' => CreateSalesReturn::route('/create'),
            'edit' => EditSalesReturn::route('/{record}/edit'),
        ];
    }
}
