<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseDownPayments\Pages;

use App\Filament\Resources\Purchasing\PurchaseDownPayments\PurchaseDownPaymentResource;
use App\Filament\Support\ListDocuments;

class ListPurchaseDownPayments extends ListDocuments
{
    protected static string $resource = PurchaseDownPaymentResource::class;

    protected string $statusColumn = 'payment_status';

    protected function statuses(): array
    {
        return ['unpaid' => __('Unpaid'), 'partial' => __('Partially paid'), 'paid' => __('Paid')];
    }
}
