<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesDownPayments\Pages;

use App\Filament\Resources\Sales\SalesDownPayments\SalesDownPaymentResource;
use App\Filament\Support\EditDocument;

class EditSalesDownPayment extends EditDocument
{
    protected static string $resource = SalesDownPaymentResource::class;

    protected function foreignFields(): array
    {
        return ['amount' => 'fc_amount'];
    }
}
