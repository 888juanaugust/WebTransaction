<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages;

use App\Filament\Resources\GeneralLedger\ExpenseAccruals\ExpenseAccrualResource;
use App\Filament\Support\EditDocument;

class EditExpenseAccrual extends EditDocument
{
    protected static string $resource = ExpenseAccrualResource::class;
}
