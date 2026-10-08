<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashBank\CashReceipts\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\CashBank\CashReceipts\CashReceiptResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\PrefillsFromMemorized;

class CreateCashReceipt extends CreateDocument
{
    use PrefillsFromMemorized;

    protected static string $resource = CashReceiptResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->prefillFromMemorized();
    }

    protected static function memorizedType(): string
    {
        return 'cash_bank_voucher_receipt';
    }

    protected function transactionType(): TransactionType
    {
        return TransactionType::CashBankVoucher;
    }
}
