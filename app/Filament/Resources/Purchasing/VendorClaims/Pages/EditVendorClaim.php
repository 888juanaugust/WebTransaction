<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorClaims\Pages;

use App\Filament\Resources\Purchasing\VendorClaims\VendorClaimResource;
use App\Filament\Support\EditDocument;

class EditVendorClaim extends EditDocument
{
    protected static string $resource = VendorClaimResource::class;
}
