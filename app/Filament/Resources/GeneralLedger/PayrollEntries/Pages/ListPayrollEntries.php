<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\PayrollEntries\Pages;

use App\Filament\Resources\GeneralLedger\PayrollEntries\PayrollEntryResource;
use App\Filament\Support\ListDocuments;

class ListPayrollEntries extends ListDocuments
{
    protected static string $resource = PayrollEntryResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
