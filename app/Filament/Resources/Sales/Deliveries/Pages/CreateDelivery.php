<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Deliveries\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\PrefillsFromSource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\TagFields;
use App\Models\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;

class CreateDelivery extends CreateDocument
{
    use PrefillsFromSource;

    protected static string $resource = DeliveryResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::DeliveryOrder;
    }

    protected function sourceModel(): string
    {
        return SalesOrder::class;
    }

    protected function dataFromSource(Model $source): array
    {
        return [
            'customer_id' => $source->customer_id,
            'taxable' => $source->taxable,
            'inclusive_tax' => $source->inclusive_tax,
            'po_number' => $source->po_number,
            'to_address' => $source->to_address,
            'shipment_id' => $source->shipment_id,
            'fob_id' => $source->fob_id,
            'description' => "From order {$source->number}",
            ...TagFields::from($source),
            'lines' => $source->isApproved() ? PricedDocumentForm::pulledLines($source->lines()->with('item')->get(), 'sales_order_line') : [],
        ];
    }
}
