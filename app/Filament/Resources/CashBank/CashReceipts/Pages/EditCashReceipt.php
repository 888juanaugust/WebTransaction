<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashReceipts\Pages;

use App\Filament\Resources\CashBank\CashReceipts\CashReceiptResource;
use App\Filament\Support\EditDocument;

class EditCashReceipt extends EditDocument
{
    protected static string $resource = CashReceiptResource::class;
}
