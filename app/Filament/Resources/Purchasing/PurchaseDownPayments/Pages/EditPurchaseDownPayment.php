<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseDownPayments\Pages;

use App\Filament\Resources\Purchasing\PurchaseDownPayments\PurchaseDownPaymentResource;
use App\Filament\Support\EditDocument;

class EditPurchaseDownPayment extends EditDocument
{
    protected static string $resource = PurchaseDownPaymentResource::class;

    protected function foreignFields(): array
    {
        return ['amount' => 'fc_amount'];
    }
}
