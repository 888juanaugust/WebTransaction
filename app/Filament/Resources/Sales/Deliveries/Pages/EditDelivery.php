<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Deliveries\Pages;

use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Support\EditDocument;

class EditDelivery extends EditDocument
{
    protected static string $resource = DeliveryResource::class;
}
