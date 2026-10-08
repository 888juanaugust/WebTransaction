<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\GoodsReceipts\Pages;

use App\Filament\Resources\Purchasing\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\ListDocuments;

class ListGoodsReceipts extends ListDocuments
{
    protected static string $resource = GoodsReceiptResource::class;
}
