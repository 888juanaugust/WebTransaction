<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchasePayments\Pages;

use App\Domain\Currency\Currencies;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PurchasePayments\PurchasePaymentResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\PayableFields;
use App\Filament\Support\SettlementLineFields;
use App\Filament\Support\SourceDocument;

/** Opened with ?source=purchase_invoice:ID (or a down payment, a return), the payment starts with that document's balance. */
class CreatePurchasePayment extends CreateDocument
{
    protected static string $resource = PurchasePaymentResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::CashBankVoucher;
    }

    public function mount(): void
    {
        parent::mount();

        $key = (string) request()->query('source');
        $doc = $key ? SourceDocument::check(PayableFields::resolve($key)) : null; // only one the user may see
        if ($doc === null) {
            return;
        }
        $state = $this->form->getRawState();
        $this->form->fill(array_merge($state, ['vendor_id' => $doc->vendor_id], Currencies::enabled() ? CurrencyFields::state($doc->currency_id, $state['trans_date'] ?? null) : []));
        $this->data['lines'] = DocumentPages::keyedRows([
            ['payable_key' => $key, 'amount' => SettlementLineFields::proposal($doc, null)['amount'], 'discount' => 0],
        ]);
    }
}
