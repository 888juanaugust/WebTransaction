<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseOrders\Pages;

use App\Filament\Resources\Purchasing\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\ListDocuments;

class ListPurchaseOrders extends ListDocuments
{
    protected static string $resource = PurchaseOrderResource::class;
}
