<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Pages\ImporBarang;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Where the "Buat produk" button used to be. Items are created in
             * bulk now — ProductResource::canCreate() is false and the create
             * route is gone — and a catalogue screen with no way at all to add
             * to it just reads as broken. So the affordance stays and points
             * at the door that still opens.
             */
            Action::make('impor')
                ->label('Impor barang')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(ImporBarang::getUrl())
                ->visible(fn () => ImporBarang::canAccess()),
        ];
    }
}
