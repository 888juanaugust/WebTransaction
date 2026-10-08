<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetChanges\Pages;

use App\Filament\Resources\FixedAssets\AssetChanges\AssetChangeResource;
use App\Filament\Support\EditDocument;

class EditAssetChange extends EditDocument
{
    protected static string $resource = AssetChangeResource::class;
}
