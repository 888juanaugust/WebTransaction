<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Deliveries\Pages;

use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Support\ListDocuments;

class ListDeliveries extends ListDocuments
{
    protected static string $resource = DeliveryResource::class;
}
