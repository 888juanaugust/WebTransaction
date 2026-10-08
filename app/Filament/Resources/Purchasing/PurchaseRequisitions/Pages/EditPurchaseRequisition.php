<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseRequisitions\Pages;

use App\Filament\Resources\Purchasing\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Support\EditDocument;

class EditPurchaseRequisition extends EditDocument
{
    protected static string $resource = PurchaseRequisitionResource::class;
}
