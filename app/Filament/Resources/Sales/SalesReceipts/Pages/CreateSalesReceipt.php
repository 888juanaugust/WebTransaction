<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReceipts\Pages;

use App\Domain\Currency\Currencies;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\CurrencyFields;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\ReceivableFields;
use App\Filament\Support\SettlementLineFields;
use App\Filament\Support\SourceDocument;

/** Opened with ?source=sales_invoice:ID (or a down payment), the receipt starts with that document's balance. */
class CreateSalesReceipt extends CreateDocument
{
    protected static string $resource = SalesReceiptResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::CashBankVoucher;
    }

    public function mount(): void
    {
        parent::mount();

        $key = (string) request()->query('source');
        $doc = $key ? SourceDocument::check(ReceivableFields::resolve($key)) : null; // only one the user may see
        if ($doc === null) {
            return;
        }
        $state = $this->form->getRawState();
        $this->form->fill(array_merge($state, ['customer_id' => $doc->customer_id], Currencies::enabled() ? CurrencyFields::state($doc->currency_id, $state['trans_date'] ?? null) : []));
        $this->data['lines'] = DocumentPages::keyedRows([
            ['receivable_key' => $key, 'amount' => SettlementLineFields::proposal($doc, null)['amount'], 'discount' => 0],
        ]);
    }
}
