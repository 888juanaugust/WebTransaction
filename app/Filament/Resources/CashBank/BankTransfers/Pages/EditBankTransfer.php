<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\BankTransfers\Pages;

use App\Filament\Resources\CashBank\BankTransfers\BankTransferResource;
use App\Filament\Support\EditDocument;

class EditBankTransfer extends EditDocument
{
    protected static string $resource = BankTransferResource::class;
}
