<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseInvoices\Pages;

use App\Filament\Resources\Purchasing\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Support\ListDocuments;

class ListPurchaseInvoices extends ListDocuments
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected string $statusColumn = 'payment_status';

    protected function statuses(): array
    {
        return ['unpaid' => __('Unpaid'), 'partial' => __('Partially paid'), 'paid' => __('Paid')];
    }
}
