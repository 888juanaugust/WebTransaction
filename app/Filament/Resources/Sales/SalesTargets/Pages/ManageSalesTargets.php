<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesTargets\Pages;

use App\Filament\Resources\Sales\SalesTargets\SalesTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ManageSalesTargets extends ListRecords
{
    protected static string $resource = SalesTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New target'))];
    }
}
