<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Items\Pages;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Inventory\OpeningStockPoster;
use App\Filament\Resources\Inventory\Items\ItemResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    /** Without the "see cost" right the purchase price is hidden, and its value never reaches the browser. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! HakAkses::canSpecial(HakKhusus::SeeCost)) {
            unset($data['purchase_price']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** The Stock tab is a posted opening adjustment, kept in step with every save. */
    protected function afterSave(): void
    {
        try {
            app(OpeningStockPoster::class)->postFor($this->record);
        } catch (\RuntimeException $e) {
            Notification::make()->title(__('Opening stock not posted'))->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
