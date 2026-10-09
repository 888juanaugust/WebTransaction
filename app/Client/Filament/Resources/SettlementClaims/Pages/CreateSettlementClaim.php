<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\SettlementClaims\Pages;

use App\Client\Domain\Claims\SettlementClaims;
use App\Client\Filament\Resources\SettlementClaims\SettlementClaimResource;
use App\Models\Sales\SalesInvoice;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Filing a claim goes through the domain service, which checks the seat, the invoice and the amount. */
class CreateSettlementClaim extends CreateRecord
{
    protected static string $resource = SettlementClaimResource::class;

    protected function fillForm(): void
    {
        parent::fillForm();
        $invoiceId = (int) request()->query('invoice');
        if ($invoiceId > 0 && ($invoice = SalesInvoice::query()->find($invoiceId)) !== null) {
            $this->form->fill(['customer_id' => $invoice->customer_id, 'sales_invoice_id' => $invoice->id, 'amount' => $invoice->balance()]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $invoice = SalesInvoice::query()->findOrFail((int) $data['sales_invoice_id']);
        try {
            return app(SettlementClaims::class)->file($invoice, (int) $data['amount'], (string) $data['account'], auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Cannot file the claim'))->body($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Claim filed; Finance will verify it.');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
