<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A new receipt is always a draft. Nothing reaches the stock ledger until
 * somebody posts it.
 */
class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['nomor'] = app(DocumentNumberGenerator::class)->nextGoodsReceiptNumber();
        $data['created_by'] = auth()->id();

        // `status` is not set here. It is not fillable — only the poster may
        // write it — and the model already defaults a new receipt to draft.

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
