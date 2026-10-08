<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashPayments\Pages;

use App\Filament\Resources\CashBank\CashPayments\CashPaymentResource;
use App\Filament\Support\EditDocument;

class EditCashPayment extends EditDocument
{
    protected static string $resource = CashPaymentResource::class;
}
