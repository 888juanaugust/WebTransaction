<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Orders\Pages;

use App\Client\Portal\Filament\Resources\Orders\OrderResource;
use App\Client\Portal\PortalDocuments;
use App\Models\Sales\Delivery;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

/** One order: its lines and totals, where it stands, the deliveries made from it (each a surat jalan) and its invoices. */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'client.portal.resources.orders.view';

    public function getTitle(): string
    {
        return (string) $this->record->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label(__('Order PDF'))->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->visible(fn () => $this->record->approval_status === SalesOrder::APPROVED)
                ->url(fn () => PortalDocuments::url($this->record), shouldOpenInNewTab: true),
            OrderResource::reorderAction()->record($this->record),
        ];
    }

    /** @return Collection<int, Delivery> */
    public function deliveries(): Collection
    {
        $lineIds = $this->record->lines()->pluck('id');

        return Delivery::query()->whereIn('id', DeliveryLine::query()->where('source_line_type', 'sales_order_line')->whereIn('source_line_id', $lineIds)->select('delivery_id'))->orderBy('trans_date')->get();
    }

    /** @return Collection<int, SalesInvoice> */
    public function invoices(): Collection
    {
        $lineIds = $this->record->lines()->pluck('id');
        $deliveryLineIds = DeliveryLine::query()->where('source_line_type', 'sales_order_line')->whereIn('source_line_id', $lineIds)->select('id');

        return SalesInvoice::query()->whereIn('id', SalesInvoiceLine::query()
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('source_line_type', 'sales_order_line')->whereIn('source_line_id', $lineIds))
                ->orWhere(fn ($q2) => $q2->where('source_line_type', 'delivery_line')->whereIn('source_line_id', $deliveryLineIds)))
            ->select('sales_invoice_id'))->orderBy('trans_date')->get();
    }
}
