<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashPayments\Pages;

use App\Filament\Resources\CashBank\CashPayments\CashPaymentResource;
use App\Filament\Support\ListDocuments;

class ListCashPayments extends ListDocuments
{
    protected static string $resource = CashPaymentResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
