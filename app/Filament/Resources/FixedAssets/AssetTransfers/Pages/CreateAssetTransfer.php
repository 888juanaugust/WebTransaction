<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetTransfers\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\FixedAssets\AssetTransfers\AssetTransferResource;
use App\Filament\Support\CreateDocument;

class CreateAssetTransfer extends CreateDocument
{
    protected static string $resource = AssetTransferResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::AssetTransfer;
    }
}
