<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseReturns\Pages;

use App\Filament\Resources\Purchasing\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Support\ListDocuments;

class ListPurchaseReturns extends ListDocuments
{
    protected static string $resource = PurchaseReturnResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
