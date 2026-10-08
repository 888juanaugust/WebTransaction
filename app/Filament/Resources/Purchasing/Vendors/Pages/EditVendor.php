<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\Vendors\Pages;

use App\Filament\Resources\Purchasing\Vendors\VendorResource;
use App\Filament\Support\PersonalDataAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [PersonalDataAction::make(), DeleteAction::make()];
    }
}
