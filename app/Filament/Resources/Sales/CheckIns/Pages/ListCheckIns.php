<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\CheckIns\Pages;

use App\Filament\Resources\Sales\CheckIns\CheckInResource;
use App\Filament\Support\ListDocuments;

class ListCheckIns extends ListDocuments
{
    protected static string $resource = CheckInResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
