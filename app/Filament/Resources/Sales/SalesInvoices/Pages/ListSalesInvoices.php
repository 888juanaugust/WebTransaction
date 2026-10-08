<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesInvoices\Pages;

use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Support\ListDocuments;

class ListSalesInvoices extends ListDocuments
{
    protected static string $resource = SalesInvoiceResource::class;

    protected string $statusColumn = 'payment_status';

    protected function statuses(): array
    {
        return ['unpaid' => __('Unpaid'), 'partial' => __('Partially paid'), 'paid' => __('Paid')];
    }
}
