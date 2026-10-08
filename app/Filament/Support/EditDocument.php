<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Fulfilment\SourceLineGuard;
use App\Domain\Sales\SellingPriceGuard;
use Filament\Resources\Pages\EditRecord;

/** A document's edit page: guarded before, re-posted and revisioned after; Approve and Reject when it waits for approval. */
abstract class EditDocument extends EditRecord
{
    private array $snapshot = [];

    /** @return array<string, string> header amounts typed in the document's currency → their fc_* columns */
    protected function foreignFields(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->foreignFields() !== [] ? CurrencyFields::fromForeign($data, $data['currency_id'] ?? null, $this->foreignFields()) : $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->snapshot = DocumentPages::beforeUpdate($this->record, $data);
        $data['updated_by'] = auth()->id();
        unset($data['series_id']);
        if ($this->foreignFields() !== []) {
            $data = CurrencyFields::toForeign($data, $data['currency_id'] ?? null, $this->foreignFields());
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (method_exists($this->record, 'refreshTotal')) {
            $this->record->refreshTotal();
        }
        app(SellingPriceGuard::class)->check($this->record);
        app(SourceLineGuard::class)->check($this->record);
        DocumentPages::afterUpdated($this->record, $this->snapshot);
    }

    protected function getHeaderActions(): array
    {
        return [...ApprovalActions::make(), DocumentPages::deleteAction()];
    }
}
