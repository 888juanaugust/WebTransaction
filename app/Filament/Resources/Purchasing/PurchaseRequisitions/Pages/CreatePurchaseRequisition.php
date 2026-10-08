<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseRequisitions\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\ReorderLines;

class CreatePurchaseRequisition extends CreateDocument
{
    protected static string $resource = PurchaseRequisitionResource::class;

    /** From Minimum Stock's selection (?reorder=): the items still to order. */
    public function mount(): void
    {
        parent::mount();
        $reorder = ReorderLines::fromRequest(priced: false);
        if ($reorder['lines'] !== []) {
            $this->data['lines'] = DocumentPages::keyedRows($reorder['lines']);
        }
    }

    protected function transactionType(): TransactionType
    {
        return TransactionType::PurchaseRequisition;
    }
}
