<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Currencies\Pages;

use App\Filament\Resources\Company\Currencies\CurrencyResource;
use App\Filament\Support\ManageMaster;

class ManageCurrencies extends ManageMaster
{
    protected static string $resource = CurrencyResource::class;
}
