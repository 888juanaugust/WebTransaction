<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\SalaryComponents\Pages;

use App\Filament\Resources\Company\SalaryComponents\SalaryComponentResource;
use App\Filament\Support\ManageMaster;

class ManageSalaryComponents extends ManageMaster
{
    protected static string $resource = SalaryComponentResource::class;
}
