<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReceipts\Pages;

use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Filament\Support\EditDocument;

class EditSalesReceipt extends EditDocument
{
    protected static string $resource = SalesReceiptResource::class;
}
