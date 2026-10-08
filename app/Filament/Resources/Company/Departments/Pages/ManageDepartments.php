<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Departments\Pages;

use App\Filament\Resources\Company\Departments\DepartmentResource;
use App\Filament\Support\ManageMaster;

class ManageDepartments extends ManageMaster
{
    protected static string $resource = DepartmentResource::class;
}
