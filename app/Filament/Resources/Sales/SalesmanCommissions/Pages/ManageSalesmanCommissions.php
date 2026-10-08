<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesmanCommissions\Pages;

use App\Filament\Resources\Sales\SalesmanCommissions\SalesmanCommissionResource;
use App\Filament\Support\ManageMaster;
use Filament\Actions\Action;

class ManageSalesmanCommissions extends ManageMaster
{
    protected static string $resource = SalesmanCommissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('statement')->label(__('Commission statement'))->icon('heroicon-m-calculator')->color('gray')
                ->url(fn () => SalesmanCommissionResource::getUrl('statement')),
            ...parent::getHeaderActions(),
        ];
    }
}
