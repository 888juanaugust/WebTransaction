<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Fobs\Pages;

use App\Filament\Resources\Company\Fobs\FobResource;
use App\Filament\Support\ManageMaster;

class ManageFobs extends ManageMaster
{
    protected static string $resource = FobResource::class;
}
