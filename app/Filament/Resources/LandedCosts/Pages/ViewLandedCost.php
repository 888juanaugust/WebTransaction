<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandedCosts\Pages;

use App\Filament\Actions\PostLandedCostAction;
use App\Filament\Resources\LandedCosts\LandedCostResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * One allocation, and the two things that can be done to it.
 *
 * A draft is checked here and then either posted or thrown away. After posting
 * there is nothing to do: it is evidence behind a figure on the balance sheet,
 * and a mistake is corrected with another document.
 */
class ViewLandedCost extends ViewRecord
{
    protected static string $resource = LandedCostResource::class;

    public function getTitle(): string
    {
        return "Biaya perolehan {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [
            PostLandedCostAction::make(),
            DeleteAction::make()
                ->label('Hapus draf')
                ->visible(fn () => $this->record->isDraft()),
        ];
    }
}
