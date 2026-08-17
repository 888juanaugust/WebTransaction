<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockTransfers extends ListRecords
{
    protected static string $resource = StockTransferResource::class;

    public function getTitle(): string
    {
        return 'Transfer gudang';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Transfer baru')
                ->visible(fn () => StockTransferResource::canCreate()),
        ];
    }
}
