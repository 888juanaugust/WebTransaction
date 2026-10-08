<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PaymentOrders\Pages;

use App\Filament\Resources\Purchasing\PaymentOrders\PaymentOrderResource;
use App\Filament\Support\EditDocument;

class EditPaymentOrder extends EditDocument
{
    protected static string $resource = PaymentOrderResource::class;
}
