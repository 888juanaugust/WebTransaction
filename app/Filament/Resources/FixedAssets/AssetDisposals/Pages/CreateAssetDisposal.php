<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetDisposals\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\FixedAssets\AssetDisposals\AssetDisposalResource;
use App\Filament\Support\CreateDocument;

class CreateAssetDisposal extends CreateDocument
{
    protected static string $resource = AssetDisposalResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::FixedAssetDisposal;
    }
}
