<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseDownPayments\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PurchaseDownPayments\PurchaseDownPaymentResource;
use App\Filament\Support\CreateDocument;

class CreatePurchaseDownPayment extends CreateDocument
{
    protected static string $resource = PurchaseDownPaymentResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::PurchaseInvoice;
    }

    protected function foreignFields(): array
    {
        return ['amount' => 'fc_amount'];
    }
}
