<?php

namespace App\Filament\Resources\PriceListImports\Pages;

use App\Filament\Resources\PriceListImports\PriceListImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPriceListImports extends ListRecords
{
    protected static string $resource = PriceListImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
