<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierBill extends CreateRecord
{
    protected static string $resource = SupplierBillResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['nomor'] = app(DocumentNumberGenerator::class)->nextSupplierBillNumber();
        $data['created_by'] = auth()->id();

        // Money columns are not set here. They are computed from the line
        // snapshots when the bill is posted, and never afterwards.

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
