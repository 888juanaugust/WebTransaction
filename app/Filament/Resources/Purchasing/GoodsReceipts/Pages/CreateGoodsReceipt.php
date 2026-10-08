<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\GoodsReceipts\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\PrefillsFromSource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\TagFields;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;

class CreateGoodsReceipt extends CreateDocument
{
    use PrefillsFromSource;

    protected static string $resource = GoodsReceiptResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::GoodsReceipt;
    }

    protected function sourceModel(): string
    {
        return PurchaseOrder::class;
    }

    protected function dataFromSource(Model $source): array
    {
        return [
            'vendor_id' => $source->vendor_id,
            'taxable' => $source->taxable,
            'inclusive_tax' => $source->inclusive_tax,
            'to_address' => $source->to_address,
            'shipment_id' => $source->shipment_id,
            'fob_id' => $source->fob_id,
            'description' => "From order {$source->number}",
            ...TagFields::from($source),
            'lines' => PricedDocumentForm::pulledLines($source->lines()->with('item')->get(), 'purchase_order_line', withPrices: false),
        ];
    }
}
