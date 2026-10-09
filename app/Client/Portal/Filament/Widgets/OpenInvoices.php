<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Widgets;

use App\Client\Portal\Filament\Resources\Invoices\InvoiceResource;
use App\Client\Portal\Portal;
use App\Client\Portal\PortalDocuments;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Models\Sales\SalesInvoice;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The invoices still open, the soonest due first, with the balance and the PDF. */
class OpenInvoices extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Open invoices'))
            ->query(fn () => SalesInvoice::query()->where('customer_id', Portal::customer()->id)->where('payment_status', '!=', 'paid')->orderBy('due_date')->orderBy('trans_date'))
            ->columns([
                TextColumn::make('number')->label(__('Invoice'))->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                Tanggal::make('due_date')->label(__('Due')),
                TextColumn::make('days')->label(__('Days'))->alignEnd()
                    ->state(fn (SalesInvoice $r) => $r->due_date && $r->due_date->isBefore(today()) ? __(':n days late', ['n' => (int) $r->due_date->diffInDays(today(), false)]) : __('due in :n days', ['n' => max(0, (int) today()->diffInDays($r->due_date ?? today(), false))]))
                    ->color(fn (SalesInvoice $r) => $r->due_date && $r->due_date->isBefore(today()) ? 'danger' : null),
                Rupiah::make('total')->label(__('fields.total')),
                Rupiah::make('balance')->label(__('Balance'))->state(fn (SalesInvoice $r) => $r->balance())->weight('medium'),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->url(fn (SalesInvoice $r) => InvoiceResource::getUrl('view', ['record' => $r])),
                Action::make('pdf')->label(__('PDF'))->icon('heroicon-m-arrow-down-tray')->color('gray')->url(fn (SalesInvoice $r) => PortalDocuments::url($r), shouldOpenInNewTab: true),
            ])
            ->paginated([5, 10])
            ->emptyStateHeading(__('Nothing open'))
            ->emptyStateDescription(__('Every invoice is settled.'));
    }
}
