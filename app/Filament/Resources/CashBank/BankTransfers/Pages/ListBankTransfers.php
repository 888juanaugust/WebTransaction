<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\BankTransfers\Pages;

use App\Filament\Resources\CashBank\BankTransfers\BankTransferResource;
use App\Filament\Support\ListDocuments;

class ListBankTransfers extends ListDocuments
{
    protected static string $resource = BankTransferResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
