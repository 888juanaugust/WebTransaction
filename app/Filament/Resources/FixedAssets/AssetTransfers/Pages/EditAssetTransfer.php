<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetTransfers\Pages;

use App\Filament\Resources\FixedAssets\AssetTransfers\AssetTransferResource;
use App\Filament\Support\EditDocument;

class EditAssetTransfer extends EditDocument
{
    protected static string $resource = AssetTransferResource::class;
}
