<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Fulfilment\SourceLineGuard;
use App\Domain\Sales\SellingPriceGuard;
use Filament\Resources\Pages\CreateRecord;

/** A document's create page: numbered from its series, posted once its lines are saved. */
abstract class CreateDocument extends CreateRecord
{
    use CreatesNumberedRecord;

    /** @return array<string, string> header amounts typed in the document's currency → their fc_* columns */
    protected function foreignFields(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        if ($this->foreignFields() !== []) {
            $data = CurrencyFields::toForeign($data, $data['currency_id'] ?? null, $this->foreignFields());
        }

        return $this->assignNumber($data);
    }

    protected function afterCreate(): void
    {
        $this->refreshTotals();
        app(SellingPriceGuard::class)->check($this->record);
        app(SourceLineGuard::class)->check($this->record);
        DocumentPages::afterCreated($this->record);
    }

    protected function refreshTotals(): void
    {
        if (method_exists($this->record, 'refreshTotal')) {
            $this->record->refreshTotal();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
