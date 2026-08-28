<?php

declare(strict_types=1);

namespace App\Filament\Resources\DebtRemovals\Pages;

use App\Filament\Resources\DebtRemovals\DebtRemovalResource;
use Filament\Resources\Pages\ListRecords;

class ListDebtRemovals extends ListRecords
{
    protected static string $resource = DebtRemovalResource::class;
}
