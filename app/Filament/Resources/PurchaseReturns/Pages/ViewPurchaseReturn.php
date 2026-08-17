<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns\Pages;

use App\Filament\Actions\PostPurchaseReturnAction;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * One return, and what can still be done to it.
 *
 * A draft is checked here and then posted, edited or thrown away. A posted one
 * has exactly one action left: printing the nota retur the supplier receives,
 * which is what turns our decision into their obligation.
 */
class ViewPurchaseReturn extends ViewRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    public function getTitle(): string
    {
        return "Retur pembelian {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cetak')
                ->label('Cetak nota retur')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (PurchaseReturn $record) => route('dokumen.retur-pembelian', $record))
                ->openUrlInNewTab()
                ->visible(fn (PurchaseReturn $record) => $record->isPosted()),

            PostPurchaseReturnAction::make(),

            EditAction::make()
                ->label('Ubah draf')
                ->visible(fn (PurchaseReturn $record) => ! $record->isPosted()),

            DeleteAction::make()
                ->label('Hapus draf')
                ->visible(fn (PurchaseReturn $record) => ! $record->isPosted()),
        ];
    }
}
