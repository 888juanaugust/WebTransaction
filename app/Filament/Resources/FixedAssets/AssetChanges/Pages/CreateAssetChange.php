<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetChanges\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\FixedAssets\AssetChanges\AssetChangeResource;
use App\Filament\Support\CreateDocument;

class CreateAssetChange extends CreateDocument
{
    protected static string $resource = AssetChangeResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::FixedAssetChange;
    }
}
