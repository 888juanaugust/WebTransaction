<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Employees\Pages;

use App\Filament\Resources\Company\Employees\EmployeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New employee'))];
    }
}
