<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesTargets\Pages;

use App\Filament\Resources\Sales\SalesTargets\SalesTargetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesTarget extends CreateRecord
{
    protected static string $resource = SalesTargetResource::class;
}
