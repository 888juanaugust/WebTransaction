<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemTransfers\Pages;

use App\Filament\Resources\Inventory\ItemTransfers\ItemTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItemTransfers extends ListRecords
{
    protected static string $resource = ItemTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New transfer'))];
    }
}
