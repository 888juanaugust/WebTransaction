<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\SiteImages\Pages;

use App\Client\Filament\Resources\SiteImages\SiteImageResource;
use App\Filament\Support\ManageMaster;

class ManageSiteImages extends ManageMaster
{
    protected static string $resource = SiteImageResource::class;
}
