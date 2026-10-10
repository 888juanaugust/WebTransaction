<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\CustomerTypes\Pages;

use App\Client\Filament\Resources\CustomerTypes\CustomerTypeResource;
use App\Filament\Support\ManageMaster;

class ManageCustomerTypes extends ManageMaster
{
    protected static string $resource = CustomerTypeResource::class;
}
