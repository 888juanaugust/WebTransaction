<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\TaxCodes\Pages;

use App\Filament\Resources\Company\TaxCodes\TaxCodeResource;
use App\Filament\Support\ManageMaster;

class ManageTaxCodes extends ManageMaster
{
    protected static string $resource = TaxCodeResource::class;
}
