<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\GoodsReceipts;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\Purchasing\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\Purchasing\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\Purchasing\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\PullAction;
use App\Filament\Support\VendorFields;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Goods Receipts: goods in from a vendor, pulled from orders, before the invoice. */
class GoodsReceiptResource extends ErpResource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $modelLabel = 'Goods receipt';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::GoodsReceipts;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(VendorFields::select()->label(__('Received from')), TransactionType::GoodsReceipt, __('Form No.'), [
                TextInput::make('receive_number')->label(__('Vendor\'s delivery note No.'))->maxLength(60),
            ]),
            Tabs::make('receipt')->tabs([
                PricedDocumentForm::linesTab(
                    before: [PullAction::make(__('Pull from orders'), 'vendor_id',
                        fn (Get $get) => PurchaseOrder::query()->where('vendor_id', $get('vendor_id'))->whereIn('status', ['pending', 'partial'])->orderByDesc('trans_date')->get(),
                        fn (int $id) => PricedDocumentForm::pulledLines(PurchaseOrder::query()->findOrFail($id)->lines()->with('item')->get(), 'purchase_order_line', withPrices: false),
                    )],
                    prices: false,
                    processed: true,
                    receipt: true,
                ),
                PricedDocumentForm::otherInfoTab(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vendor'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('receive_number')->label(__('Delivery note No.'))->placeholder('—'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('vendor.name')->label(__('fields.vendor'))->searchable(),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', 'closed' => 'gray', default => 'info'
                    }),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('vendor_id')->label(__('Received from'))->relationship('vendor', 'name')->searchable()])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('invoice')->label(__('Invoice'))->icon('heroicon-m-document-text')->color('primary')
                    ->visible(fn (GoodsReceipt $record) => in_array($record->status, ['pending', 'partial'], true) && PurchaseInvoiceResource::canCreate())
                    ->url(fn (GoodsReceipt $record) => PurchaseInvoiceResource::getUrl('create', ['source' => 'receipt:'.$record->id])),
                PrintAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceipts::route('/'),
            'create' => CreateGoodsReceipt::route('/create'),
            'edit' => EditGoodsReceipt::route('/{record}/edit'),
        ];
    }
}
