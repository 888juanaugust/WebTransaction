<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Shipments\Pages;

use App\Filament\Resources\Company\Shipments\ShipmentResource;
use App\Filament\Support\ManageMaster;

class ManageShipments extends ManageMaster
{
    protected static string $resource = ShipmentResource::class;
}
