<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Settlement\SettlementService;
use App\Filament\Resources\CashBank\CashPayments\CashPaymentResource;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/** "Pay" on an expense accrual or a payroll entry: a new payment with one line settling what is open. */
final class PayAction
{
    public static function make(): Action
    {
        return Action::make('pay')
            ->label(__('Pay'))
            ->icon('heroicon-m-banknotes')
            ->color('gray')
            ->url(fn (Model $record): string => CashPaymentResource::getUrl('create', ['settle' => $record->getMorphClass().':'.$record->getKey()]))
            ->visible(fn (Model $record): bool => CashPaymentResource::canCreate() && app(SettlementService::class)->balance($record) > 0);
    }
}
