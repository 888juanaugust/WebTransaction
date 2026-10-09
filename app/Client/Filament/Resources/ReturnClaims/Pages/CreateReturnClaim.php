<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ReturnClaims\Pages;

use App\Client\Domain\Claims\ReturnClaims;
use App\Client\Filament\Resources\ReturnClaims\ReturnClaimResource;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Filing goes through the domain service, which checks the seat, the invoice and every line's room. */
class CreateReturnClaim extends CreateRecord
{
    protected static string $resource = ReturnClaimResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $invoice = SalesInvoice::query()->findOrFail((int) $data['sales_invoice_id']);
        $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
        try {
            return app(ReturnClaims::class)->file($invoice, $warehouse, array_values((array) ($data['lines'] ?? [])), (string) $data['reason'], auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Cannot file the claim'))->body($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Claim filed; Inventory will verify it.');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
