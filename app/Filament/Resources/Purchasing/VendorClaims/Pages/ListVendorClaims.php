<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorClaims\Pages;

use App\Filament\Resources\Purchasing\VendorClaims\VendorClaimResource;
use App\Filament\Support\ListDocuments;

class ListVendorClaims extends ListDocuments
{
    protected static string $resource = VendorClaimResource::class;

    protected function statuses(): array
    {
        return ['pending' => __('Pending'), 'processed' => __('Settled')];
    }
}
