<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetTransfers\Pages;

use App\Filament\Resources\FixedAssets\AssetTransfers\AssetTransferResource;
use App\Filament\Support\ListDocuments;

class ListAssetTransfers extends ListDocuments
{
    protected static string $resource = AssetTransferResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
