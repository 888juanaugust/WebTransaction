<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * New orders are always created as drafts.
 *
 * Nothing is priced and no stock is held until somebody confirms — that is
 * what `confirmed` is for, and it is where the credit check and the stock
 * reservation live.
 */
class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['nomor'] = app(DocumentNumberGenerator::class)->nextOrderNumber();
        $data['created_by'] = auth()->id();
        $data['sales_user_id'] = auth()->id();

        // `status` is not set here on purpose. It is not fillable — only the
        // state machine may write it — and the model already defaults a new
        // order to draft. Assigning it here raises MassAssignmentException,
        // which is the guard doing its job.

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
