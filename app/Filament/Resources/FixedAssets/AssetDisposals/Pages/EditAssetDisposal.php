<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetDisposals\Pages;

use App\Filament\Resources\FixedAssets\AssetDisposals\AssetDisposalResource;
use App\Filament\Support\EditDocument;

class EditAssetDisposal extends EditDocument
{
    protected static string $resource = AssetDisposalResource::class;
}
