<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseOrders\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\PrefillsFromSource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\ReorderLines;
use App\Filament\Support\TagFields;
use App\Models\Purchasing\PurchaseRequisition;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseOrder extends CreateDocument
{
    use PrefillsFromSource {
        mount as mountFromSource;
    }

    protected static string $resource = PurchaseOrderResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::PurchaseOrder;
    }

    /** From a requisition (?source=), or from Minimum Stock's selection (?reorder=). */
    public function mount(): void
    {
        $this->mountFromSource();
        $reorder = ReorderLines::fromRequest(priced: true);
        if ($reorder['lines'] !== []) {
            if ($reorder['vendor_id']) {
                $this->data['vendor_id'] = $reorder['vendor_id'];
            }
            $this->data['lines'] = DocumentPages::keyedRows($reorder['lines']);
        }
    }

    protected function sourceModel(): string
    {
        return PurchaseRequisition::class;
    }

    protected function dataFromSource(Model $source): array
    {
        return [
            'description' => "From requisition {$source->number}",
            ...TagFields::from($source),
            'lines' => PricedDocumentForm::pulledLines($source->lines()->with('item')->get(), 'purchase_requisition_line', withPrices: false),
        ];
    }
}
