<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesTargets\Pages;

use App\Filament\Resources\Sales\SalesTargets\SalesTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesTarget extends EditRecord
{
    protected static string $resource = SalesTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
