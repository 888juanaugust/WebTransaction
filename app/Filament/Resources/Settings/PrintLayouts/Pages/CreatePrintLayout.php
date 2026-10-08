<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\PrintLayouts\Pages;

use App\Filament\Resources\Settings\PrintLayouts\PrintLayoutResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrintLayout extends CreateRecord
{
    protected static string $resource = PrintLayoutResource::class;
}
