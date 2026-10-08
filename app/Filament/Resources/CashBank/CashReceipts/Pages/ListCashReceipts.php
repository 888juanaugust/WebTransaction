<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashReceipts\Pages;

use App\Filament\Resources\CashBank\CashReceipts\CashReceiptResource;
use App\Filament\Support\ListDocuments;

class ListCashReceipts extends ListDocuments
{
    protected static string $resource = CashReceiptResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
