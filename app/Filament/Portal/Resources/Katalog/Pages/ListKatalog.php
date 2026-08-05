<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Katalog\Pages;

use App\Filament\Portal\Resources\Katalog\KatalogResource;
use Filament\Resources\Pages\ListRecords;

class ListKatalog extends ListRecords
{
    protected static string $resource = KatalogResource::class;

    public function getTitle(): string
    {
        return 'Katalog & harga Anda';
    }
}
