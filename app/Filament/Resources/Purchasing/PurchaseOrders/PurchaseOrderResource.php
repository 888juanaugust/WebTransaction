<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseOrders;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Purchasing\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Resources\Purchasing\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\Purchasing\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\Purchasing\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\PullAction;
use App\Filament\Support\VendorFields;
use App\Models\Inventory\Item;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseRequisition;
use App\Models\Purchasing\VendorPrice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Purchase Orders: ordered from a vendor at the vendor's price; receipts and invoices pull from them. */
class PurchaseOrderResource extends ErpResource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $modelLabel = 'Purchase order';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PurchaseOrders;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(VendorFields::select(), TransactionType::PurchaseOrder),
            Tabs::make('order')->tabs([
                PricedDocumentForm::linesTab(
                    before: [PullAction::make(__('Pull from requisitions'), 'vendor_id',
                        fn (Get $get) => PurchaseRequisition::query()->whereIn('status', ['pending', 'partial'])->orderByDesc('trans_date')->get(),
                        fn (int $id) => PricedDocumentForm::pulledLines(PurchaseRequisition::query()->findOrFail($id)->lines()->with('item')->get(), 'purchase_requisition_line', withPrices: false),
                    )],
                    processed: true,
                    priceResolver: fn (Item $item, Get $get) => VendorPrice::lookup((int) $get('../../vendor_id'), $item->id, $get('../../trans_date') ?: today(), $get('unit_id') ? (int) $get('unit_id') : null) ?? (string) $item->purchase_price,
                ),
                PricedDocumentForm::otherInfoTab([VendorFields::paymentTerm(), VendorFields::bankAccount()]),
                PricedDocumentForm::chargesTab(),
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
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', 'closed' => 'gray', default => 'info'
                    }),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('vendor_id')->label(__('fields.vendor'))->relationship('vendor', 'name')->searchable()])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('receive')->label(__('Receive'))->icon('heroicon-m-inbox-arrow-down')->color('primary')
                    ->visible(fn (PurchaseOrder $record) => in_array($record->status, ['pending', 'partial'], true) && GoodsReceiptResource::canCreate())
                    ->url(fn (PurchaseOrder $record) => GoodsReceiptResource::getUrl('create', ['source' => $record->id])),
                Action::make('invoice')->label(__('Invoice'))->icon('heroicon-m-document-text')->color('gray')
                    ->visible(fn (PurchaseOrder $record) => in_array($record->status, ['pending', 'partial'], true) && PurchaseInvoiceResource::canCreate())
                    ->url(fn (PurchaseOrder $record) => PurchaseInvoiceResource::getUrl('create', ['source' => 'order:'.$record->id])),
                PrintAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
