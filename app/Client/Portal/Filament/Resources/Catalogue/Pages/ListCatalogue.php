<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Catalogue\Pages;

use App\Client\Portal\Filament\Resources\Catalogue\CatalogueResource;
use Filament\Resources\Pages\ListRecords;

class ListCatalogue extends ListRecords
{
    protected static string $resource = CatalogueResource::class;

    public function getTitle(): string
    {
        return __('Catalogue and your prices');
    }
}
