<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PaymentOrders\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PaymentOrders\PaymentOrderResource;
use App\Filament\Support\CreateDocument;

class CreatePaymentOrder extends CreateDocument
{
    protected static string $resource = PaymentOrderResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::PaymentOrder;
    }
}
