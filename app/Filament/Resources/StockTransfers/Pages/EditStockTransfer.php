<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Actions\PostStockTransferAction;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Resources\Pages\EditRecord;

class EditStockTransfer extends EditRecord
{
    protected static string $resource = StockTransferResource::class;

    public function getTitle(): string
    {
        return "Transfer {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [PostStockTransferAction::make()];
    }
}
