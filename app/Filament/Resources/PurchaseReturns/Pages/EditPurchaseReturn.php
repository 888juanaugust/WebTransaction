<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns\Pages;

use App\Filament\Actions\PostPurchaseReturnAction;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Cutting a drawn-up return down to what is really going back.
 *
 * The only screen in this document's life where anything is editable, and only
 * quantities are. After posting there is nothing to change: the goods have
 * left and the payable has moved.
 */
class EditPurchaseReturn extends EditRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    public function getTitle(): string
    {
        return "Retur {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [
            PostPurchaseReturnAction::make(),
            DeleteAction::make()->label('Hapus draf'),
        ];
    }

    /** Straight to the detail screen, which is where the split is shown. */
    protected function getRedirectUrl(): string
    {
        return PurchaseReturnResource::getUrl('view', ['record' => $this->record]);
    }
}
