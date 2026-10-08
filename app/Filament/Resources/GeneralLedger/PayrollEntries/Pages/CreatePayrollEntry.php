<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\PayrollEntries\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\GeneralLedger\PayrollEntries\PayrollEntryResource;
use App\Filament\Support\CreateDocument;

class CreatePayrollEntry extends CreateDocument
{
    protected static string $resource = PayrollEntryResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::PayrollEntry;
    }
}
