<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PaymentOrders\Pages;

use App\Filament\Resources\Purchasing\PaymentOrders\PaymentOrderResource;
use App\Filament\Support\ListDocuments;

class ListPaymentOrders extends ListDocuments
{
    protected static string $resource = PaymentOrderResource::class;
}
