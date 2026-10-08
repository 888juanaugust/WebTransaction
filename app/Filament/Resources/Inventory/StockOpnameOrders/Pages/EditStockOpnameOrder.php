<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameOrders\Pages;

use App\Filament\Resources\Inventory\StockOpnameOrders\StockOpnameOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStockOpnameOrder extends EditRecord
{
    protected static string $resource = StockOpnameOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->hidden(fn () => $this->record->results()->exists())];
    }
}
