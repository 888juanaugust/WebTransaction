<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ExpenseClaims\Pages;

use App\Client\Domain\Claims\ExpenseClaims;
use App\Client\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Models\Sales\Customer;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Filing goes through the domain service: the filer is the sales user, the customer one of their own. */
class CreateExpenseClaim extends CreateRecord
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $customer = filled($data['customer_id'] ?? null) ? Customer::query()->find((int) $data['customer_id']) : null;
        try {
            return app(ExpenseClaims::class)->file(auth()->user(), $customer, (string) $data['trans_date'], (int) $data['amount'], (string) $data['description']);
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
