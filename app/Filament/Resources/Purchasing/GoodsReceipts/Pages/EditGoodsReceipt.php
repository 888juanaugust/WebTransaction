<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\GoodsReceipts\Pages;

use App\Filament\Resources\Purchasing\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\EditDocument;

class EditGoodsReceipt extends EditDocument
{
    protected static string $resource = GoodsReceiptResource::class;
}
