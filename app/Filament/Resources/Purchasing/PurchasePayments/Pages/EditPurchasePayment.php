<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchasePayments\Pages;

use App\Filament\Resources\Purchasing\PurchasePayments\PurchasePaymentResource;
use App\Filament\Support\EditDocument;

class EditPurchasePayment extends EditDocument
{
    protected static string $resource = PurchasePaymentResource::class;
}
