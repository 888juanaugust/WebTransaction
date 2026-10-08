<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\BankTransfers\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\CashBank\BankTransfers\BankTransferResource;
use App\Filament\Support\CreateDocument;

class CreateBankTransfer extends CreateDocument
{
    protected static string $resource = BankTransferResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::BankTransfer;
    }
}
