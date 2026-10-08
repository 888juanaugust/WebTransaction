<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseOrders\Pages;

use App\Filament\Resources\Purchasing\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\EditDocument;

class EditPurchaseOrder extends EditDocument
{
    protected static string $resource = PurchaseOrderResource::class;
}
