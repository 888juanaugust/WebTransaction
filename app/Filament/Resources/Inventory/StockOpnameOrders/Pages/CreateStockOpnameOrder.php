<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameOrders\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Inventory\StockOpnameOrders\StockOpnameOrderResource;
use App\Filament\Support\CreatesNumberedRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateStockOpnameOrder extends CreateRecord
{
    use CreatesNumberedRecord;

    protected static string $resource = StockOpnameOrderResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::StockOpnameOrder;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $this->assignNumber($data);
    }
}
