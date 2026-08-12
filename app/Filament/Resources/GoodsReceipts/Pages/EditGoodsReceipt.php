<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Actions\PostGoodsReceiptAction;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Resources\Pages\EditRecord;

class EditGoodsReceipt extends EditRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    public function getTitle(): string
    {
        return "Penerimaan {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [PostGoodsReceiptAction::make()];
    }
}
