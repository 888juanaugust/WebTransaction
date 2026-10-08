<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Deliveries;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\Deliveries\Pages\CreateDelivery;
use App\Filament\Resources\Sales\Deliveries\Pages\EditDelivery;
use App\Filament\Resources\Sales\Deliveries\Pages\ListDeliveries;
use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\PullAction;
use App\Filament\Support\SalesLinesTab;
use App\Filament\Support\TagFields;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Delivery Orders: goods out to the customer from approved orders, partially or in several deliveries; "Invoice" bills them. */
class DeliveryResource extends ErpResource
{
    protected static ?string $model = Delivery::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'Delivery order';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::DeliveryOrders;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(CustomerFields::select(label: __('Ship to')), TransactionType::DeliveryOrder, __('Delivery No.'), [
                Select::make('shipment_id')->label(__('fields.shipment'))->relationship('shipment', 'name')->preload()->native(false),
            ]),
            Tabs::make('delivery')->tabs([
                SalesLinesTab::make(
                    before: [PullAction::make(__('Pull from orders'), 'customer_id',
                        fn (Get $get) => SalesOrder::query()->where('customer_id', $get('customer_id'))->where('approval_status', SalesOrder::APPROVED)->whereIn('status', ['pending', 'partial'])->orderByDesc('trans_date')->get(),
                        fn (int $id) => PricedDocumentForm::pulledLines(SalesOrder::query()->findOrFail($id)->lines()->with('item')->get(), 'sales_order_line'),
                    )],
                    prices: false,
                    processed: true,
                ),
                Tab::make(__('fields.other_info'))->columns(2)->schema([
                    BranchFields::select(),
                    ...TagFields::header(),
                    TextInput::make('po_number')->label(__('fields.po_number'))->maxLength(60),
                    Select::make('fob_id')->label(__('fields.fob'))->relationship('fob', 'name')->preload()->native(false),
                    Textarea::make('to_address')->label(__('Address'))->rows(2),
                    Textarea::make('description')->label(__('fields.description'))->rows(2),
                    Hidden::make('taxable')->default(true),
                    Hidden::make('inclusive_tax')->default(false),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'shipment']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable(),
                TextColumn::make('shipment.name')->label(__('fields.shipment'))->placeholder('—'),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', 'closed' => 'gray', default => 'info'
                    }),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('customer_id')->label(__('Ship to'))->relationship('customer', 'name')->searchable(),
                SelectFilter::make('shipment_id')->label(__('fields.shipment'))->relationship('shipment', 'name'),
            ])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('invoice')->label(__('Invoice'))->icon('heroicon-m-document-text')->color('primary')
                    ->visible(fn (Delivery $record) => in_array($record->status, ['pending', 'partial'], true) && SalesInvoiceResource::canCreate())
                    ->url(fn (Delivery $record) => SalesInvoiceResource::getUrl('create', ['source' => 'delivery:'.$record->id])),
                PrintAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveries::route('/'),
            'create' => CreateDelivery::route('/create'),
            'edit' => EditDelivery::route('/{record}/edit'),
        ];
    }
}
