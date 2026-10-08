<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseReturns\Pages;

use App\Filament\Resources\Purchasing\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Support\EditDocument;

class EditPurchaseReturn extends EditDocument
{
    protected static string $resource = PurchaseReturnResource::class;
}
