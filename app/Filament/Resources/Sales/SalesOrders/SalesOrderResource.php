<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesOrders;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Resources\Sales\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\Sales\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\Sales\SalesOrders\Pages\ListSalesOrders;
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
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesQuotation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Sales Orders: what the customer ordered; approved under the approval rules before it ships; deliveries and invoices pull from it. */
class SalesOrderResource extends ErpResource
{
    protected static ?string $model = SalesOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $modelLabel = 'Sales order';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesOrders;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(CustomerFields::select(label: __('Ordered by')), TransactionType::SalesOrder, __('Order No.')),
            Tabs::make('order')->tabs([
                SalesLinesTab::make(
                    before: [PullAction::make(__('Pull from quotations'), 'customer_id',
                        fn (Get $get) => SalesQuotation::query()->where('customer_id', $get('customer_id'))->whereIn('status', ['pending', 'partial'])->orderByDesc('trans_date')->get(),
                        fn (int $id) => PricedDocumentForm::pulledLines(SalesQuotation::query()->findOrFail($id)->lines()->with('item')->get(), 'sales_quotation_line'),
                    )],
                    processed: true,
                ),
                PricedDocumentForm::otherInfoTab([CustomerFields::paymentTerm(), TextInput::make('po_number')->label(__('fields.po_number'))->maxLength(60)]),
                PricedDocumentForm::chargesTab(),
            ]),
        ])->columns(1);
    }

    public static function approveAction(): Action
    {
        return ApprovalActions::approve(fn (SalesOrder $record) => HakAkses::canSpecial(HakKhusus::SeeCreditData)
            ? 'Credit check: '.CustomerFields::exposureSummary($record->customer).'. Approving lets the order ship.'
            : __('Approving lets the order ship.'));
    }

    public static function rejectAction(): Action
    {
        return ApprovalActions::reject();
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
                TextColumn::make('approval_status')->label(__('Approval'))->badge()->formatStateUsing(fn (string $state) => Format::code($state, 'approval'))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', default => 'warning'
                    }),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', 'closed' => 'gray', default => 'info'
                    }),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('customer_id')->label(__('Ordered by'))->relationship('customer', 'name')->searchable(),
                SelectFilter::make('approval_status')->label(__('Approval'))->options(['awaiting' => __('Awaiting approval'), 'approved' => __('Approved'), 'rejected' => __('Rejected')]),
                TernaryFilter::make('is_printed')->label(__('fields.is_printed')),
            ])
            ->recordActions([
                EditAction::make(),
                self::approveAction(),
                self::rejectAction(),
                Action::make('deliver')->label(__('Deliver'))->icon('heroicon-m-truck')->color('primary')
                    ->visible(fn (SalesOrder $record) => $record->isApproved() && in_array($record->status, ['pending', 'partial'], true) && DeliveryResource::canCreate())
                    ->url(fn (SalesOrder $record) => DeliveryResource::getUrl('create', ['source' => $record->id])),
                Action::make('invoice')->label(__('Invoice'))->icon('heroicon-m-document-text')->color('gray')
                    ->visible(fn (SalesOrder $record) => $record->isApproved() && in_array($record->status, ['pending', 'partial'], true) && SalesInvoiceResource::canCreate())
                    ->url(fn (SalesOrder $record) => SalesInvoiceResource::getUrl('create', ['source' => 'order:'.$record->id])),
                PrintAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesOrders::route('/'),
            'create' => CreateSalesOrder::route('/create'),
            'edit' => EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
