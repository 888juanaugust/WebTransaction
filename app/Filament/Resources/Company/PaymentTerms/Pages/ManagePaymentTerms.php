<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\PaymentTerms\Pages;

use App\Filament\Resources\Company\PaymentTerms\PaymentTermResource;
use App\Filament\Support\ManageMaster;

class ManagePaymentTerms extends ManageMaster
{
    protected static string $resource = PaymentTermResource::class;
}
