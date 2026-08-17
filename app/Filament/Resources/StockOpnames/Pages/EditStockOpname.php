<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Filament\Actions\PostStockOpnameAction;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use Filament\Resources\Pages\EditRecord;

class EditStockOpname extends EditRecord
{
    protected static string $resource = StockOpnameResource::class;

    public function getTitle(): string
    {
        return "Opname {$this->record->nomor}";
    }

    /**
     * Whoever saves the count is recorded as having counted it.
     *
     * That is what makes the separation enforceable at posting: without a name
     * on the count, "the counter cannot approve their own count" has nothing
     * to compare against.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['counted_by'] = auth()->id();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [PostStockOpnameAction::make()];
    }
}
