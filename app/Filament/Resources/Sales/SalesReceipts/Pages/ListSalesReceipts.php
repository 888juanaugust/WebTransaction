<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReceipts\Pages;

use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Filament\Support\ListDocuments;

class ListSalesReceipts extends ListDocuments
{
    protected static string $resource = SalesReceiptResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
