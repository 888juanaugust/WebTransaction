<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashPayments\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\CashBank\CashPayments\CashPaymentResource;
use App\Filament\Support\AccrualFields;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\PrefillsFromMemorized;

class CreateCashPayment extends CreateDocument
{
    use PrefillsFromMemorized;

    protected static string $resource = CashPaymentResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->prefillFromMemorized();
        $this->prefillSettlement();
    }

    /** Opened from an accrual's or a payroll entry's "Pay" action: one line settling what is open. */
    private function prefillSettlement(): void
    {
        $key = (string) request()->query('settle', '');
        $open = $key !== '' ? AccrualFields::openFor()->get($key) : null;
        if ($open === null) {
            return;
        }
        $this->form->fill([...$this->form->getRawState(), 'description' => __('Payment of :number', ['number' => $open['model']->number])]);
        // A relationship repeater reloads from the record on fill, so the line goes straight into the page state.
        $this->data['lines'] = DocumentPages::keyedRows([CashPaymentResource::settlingLine($key, $open)]);
    }

    protected static function memorizedType(): string
    {
        return 'cash_bank_voucher_payment';
    }

    protected function transactionType(): TransactionType
    {
        return TransactionType::CashBankVoucher;
    }
}
