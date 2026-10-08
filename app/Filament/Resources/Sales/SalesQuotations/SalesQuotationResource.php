<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesQuotations;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Resources\Sales\SalesQuotations\Pages\CreateSalesQuotation;
use App\Filament\Resources\Sales\SalesQuotations\Pages\EditSalesQuotation;
use App\Filament\Resources\Sales\SalesQuotations\Pages\ListSalesQuotations;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\SalesLinesTab;
use App\Models\Sales\SalesQuotation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Sales Quotations: an offer at the customer's prices; one quotation can become several orders. */
class SalesQuotationResource extends ErpResource
{
    protected static ?string $model = SalesQuotation::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?string $modelLabel = 'Sales quotation';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesQuotations;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(CustomerFields::select(label: __('Ordered by')), TransactionType::SalesQuotation),
            Tabs::make('quotation')->tabs([
                SalesLinesTab::make(warehouse: false, processed: true),
                PricedDocumentForm::otherInfoTab([CustomerFields::paymentTerm()], shipping: false),
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
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('customer_id')->label(__('Ordered by'))->relationship('customer', 'name')->searchable(), TernaryFilter::make('is_printed')->label(__('fields.is_printed'))])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('order')->label(__('Create order'))->icon('heroicon-m-arrow-right-circle')->color('primary')
                    ->visible(fn (SalesQuotation $record) => in_array($record->status, ['pending', 'partial'], true) && SalesOrderResource::canCreate())
                    ->url(fn (SalesQuotation $record) => SalesOrderResource::getUrl('create', ['source' => $record->id])),
                PrintAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesQuotations::route('/'),
            'create' => CreateSalesQuotation::route('/create'),
            'edit' => EditSalesQuotation::route('/{record}/edit'),
        ];
    }
}
