<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseRequisitions\Pages;

use App\Filament\Resources\Purchasing\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Support\ListDocuments;

class ListPurchaseRequisitions extends ListDocuments
{
    protected static string $resource = PurchaseRequisitionResource::class;
}
