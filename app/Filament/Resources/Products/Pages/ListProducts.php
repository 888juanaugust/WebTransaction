<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Filament's CreateAction does not consult the resource, so
             * without this a sales rep saw "Buat produk", pressed it, and got
             * a 403 from CreateProduct::authorizeAccess. The route was never
             * open; the button was just lying about it.
             */
            CreateAction::make()
                ->visible(fn () => ProductResource::canCreate()),
        ];
    }
}
