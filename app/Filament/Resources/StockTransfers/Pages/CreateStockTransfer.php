<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    public function getTitle(): string
    {
        return 'Transfer baru';
    }

    protected function getRedirectUrl(): string
    {
        // Back to the draft: nothing has moved yet, and the next thing anybody
        // wants is the Posting button.
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
