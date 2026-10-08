<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\PayrollEntries\Pages;

use App\Filament\Resources\GeneralLedger\PayrollEntries\PayrollEntryResource;
use App\Filament\Support\EditDocument;

class EditPayrollEntry extends EditDocument
{
    protected static string $resource = PayrollEntryResource::class;
}
