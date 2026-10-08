<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesDownPayments\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesDownPayments\SalesDownPaymentResource;
use App\Filament\Support\CreateDocument;

class CreateSalesDownPayment extends CreateDocument
{
    protected static string $resource = SalesDownPaymentResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::SalesInvoice;
    }

    protected function foreignFields(): array
    {
        return ['amount' => 'fc_amount'];
    }
}
