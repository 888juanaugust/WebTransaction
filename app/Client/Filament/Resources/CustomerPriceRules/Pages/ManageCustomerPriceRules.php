<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\CustomerPriceRules\Pages;

use App\Client\Filament\Resources\CustomerPriceRules\CustomerPriceRuleResource;
use App\Filament\Support\ManageMaster;

class ManageCustomerPriceRules extends ManageMaster
{
    protected static string $resource = CustomerPriceRuleResource::class;
}
