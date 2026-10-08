<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Items\Pages;

use App\Domain\Inventory\OpeningStockPoster;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Inventory\Items\ItemResource;
use App\Filament\Support\CreatesNumberedRecord;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateItem extends CreateRecord
{
    use CreatesNumberedRecord;

    protected static string $resource = ItemResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::Item;
    }

    /** The Stock tab is a posted opening adjustment, kept in step with every save. */
    protected function afterCreate(): void
    {
        try {
            app(OpeningStockPoster::class)->postFor($this->record);
        } catch (\RuntimeException $e) {
            Notification::make()->title(__('Opening stock not posted'))->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
