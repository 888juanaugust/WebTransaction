<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\FixedAssets\Pages;

use App\Filament\Resources\FixedAssets\FixedAssets\FixedAssetResource;
use App\Filament\Support\EditDocument;

class EditFixedAsset extends EditDocument
{
    protected static string $resource = FixedAssetResource::class;
}
