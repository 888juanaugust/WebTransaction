<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameResults\Pages;

use App\Filament\Resources\Inventory\StockOpnameResults\StockOpnameResultResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockOpnameResults extends ListRecords
{
    protected static string $resource = StockOpnameResultResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New count result'))];
    }
}
