<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetChanges\Pages;

use App\Filament\Resources\FixedAssets\AssetChanges\AssetChangeResource;
use App\Filament\Support\ListDocuments;

class ListAssetChanges extends ListDocuments
{
    protected static string $resource = AssetChangeResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
