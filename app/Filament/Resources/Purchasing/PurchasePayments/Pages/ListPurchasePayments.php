<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchasePayments\Pages;

use App\Filament\Resources\Purchasing\PurchasePayments\PurchasePaymentResource;
use App\Filament\Support\ListDocuments;

class ListPurchasePayments extends ListDocuments
{
    protected static string $resource = PurchasePaymentResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
