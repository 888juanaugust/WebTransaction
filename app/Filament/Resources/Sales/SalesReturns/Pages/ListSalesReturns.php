<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReturns\Pages;

use App\Filament\Resources\Sales\SalesReturns\SalesReturnResource;
use App\Filament\Support\ListDocuments;

class ListSalesReturns extends ListDocuments
{
    protected static string $resource = SalesReturnResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
