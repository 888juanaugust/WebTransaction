<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameOrders\Pages;

use App\Filament\Resources\Inventory\StockOpnameOrders\StockOpnameOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockOpnameOrders extends ListRecords
{
    protected static string $resource = StockOpnameOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New count order'))];
    }
}
