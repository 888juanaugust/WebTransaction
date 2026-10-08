<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\CheckIns\Pages;

use App\Filament\Resources\Sales\CheckIns\CheckInResource;
use App\Filament\Support\EditDocument;

class EditCheckIn extends EditDocument
{
    protected static string $resource = CheckInResource::class;
}
