<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetDisposals\Pages;

use App\Filament\Resources\FixedAssets\AssetDisposals\AssetDisposalResource;
use App\Filament\Support\ListDocuments;

class ListAssetDisposals extends ListDocuments
{
    protected static string $resource = AssetDisposalResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
