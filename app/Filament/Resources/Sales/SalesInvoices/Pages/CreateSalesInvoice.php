<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesInvoices\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\SourceDocument;
use App\Filament\Support\TagFields;
use App\Models\Sales\Delivery;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;

/** Opened with ?source=delivery:ID or ?source=order:ID, the invoice starts from that document. */
class CreateSalesInvoice extends CreateDocument
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::SalesInvoice;
    }

    public function mount(): void
    {
        parent::mount();

        [$kind, $id] = array_pad(explode(':', (string) request()->query('source'), 2), 2, null);
        // Only a document the user may see: in their branches, with the view right on its screen, approved.
        $source = match ($kind) {
            'delivery' => SourceDocument::find(Delivery::class, (int) $id),
            'order' => SourceDocument::find(SalesOrder::class, (int) $id),
            default => null,
        };
        if ($source === null) {
            return;
        }
        $state = $this->form->getRawState();
        $this->form->fill(array_merge($state, CurrencyFields::fromSource($source, $state['trans_date'] ?? null), [
            'customer_id' => $source->customer_id,
            'taxable' => $source->taxable,
            'inclusive_tax' => $source->inclusive_tax,
            'payment_term_id' => $source->payment_term_id ?? $source->customer?->payment_term_id,
            'po_number' => $source->po_number,
            'to_address' => $source->to_address,
            'discount_percent' => (string) $source->discount_percent,
            'description' => "From {$source->number}",
            ...TagFields::from($source),
        ]));
        $lines = PricedDocumentForm::pulledLines($source->lines()->with('item')->get(), $kind === 'delivery' ? 'delivery_line' : 'sales_order_line');
        if ($kind === 'delivery') {
            // A delivery carries the order's prices when it came from one; the invoice bills them.
            foreach ($lines as &$line) {
                $orderLine = ($deliveryLine = DeliveryLine::query()->find($line['source_line_id'])) && $deliveryLine->source_line_type === 'sales_order_line'
                    ? SalesOrderLine::query()->find($deliveryLine->source_line_id) : null;
                if ($orderLine) {
                    $line['unit_price'] = (string) ($orderLine->fc_unit_price ?? $orderLine->unit_price);
                    $line['discount_percent'] = (string) $orderLine->discount_percent;
                    $line['tax_code_id'] = $orderLine->tax_code_id;
                    $line['salesman_id'] = $orderLine->salesman_id;
                }
            }
        }
        $this->data['lines'] = DocumentPages::keyedRows($lines);
    }
}
