<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameResults\Pages;

use App\Domain\Inventory\OpnameApprover;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Inventory\StockOpnameResults\StockOpnameResultResource;
use App\Filament\Support\CreatesNumberedRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateStockOpnameResult extends CreateRecord
{
    use CreatesNumberedRecord;

    protected static string $resource = StockOpnameResultResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::StockOpnameResult;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $this->assignNumber($data);
    }

    protected function afterCreate(): void
    {
        app(OpnameApprover::class)->snapshotSystemQuantities($this->record->load('order', 'lines'));
    }
}
