<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Wired to the resource's answer on purpose. Filament's
             * DeleteAction authorises nothing by itself — it is a confirm
             * modal and a `$record->delete()` — so a `canDelete()` on the
             * resource is a declaration nobody consults unless the button
             * asks. Measured before this line existed: a Gudang clerk pressed
             * it and the SKU went, ledger and all.
             *
             * Product::deleting still refuses a SKU with history underneath
             * this, because tinker and a queued job never reach here.
             */
            DeleteAction::make()
                ->visible(fn (Product $record) => ProductResource::canDelete($record)),
        ];
    }
}
