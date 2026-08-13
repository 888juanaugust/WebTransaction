<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Filament\Resources\SupplierBills\SupplierBillResource;
use Filament\Resources\Pages\EditRecord;

class EditSupplierBill extends EditRecord
{
    protected static string $resource = SupplierBillResource::class;

    public function getTitle(): string
    {
        return "Tagihan {$this->record->nomor}";
    }
}
