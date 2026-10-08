<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\JournalVouchers\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\GeneralLedger\JournalVouchers\JournalVoucherResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\PrefillsFromMemorized;

class CreateJournalVoucher extends CreateDocument
{
    use PrefillsFromMemorized;

    protected static string $resource = JournalVoucherResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->prefillFromMemorized();
    }

    protected static function memorizedType(): string
    {
        return 'journal_voucher';
    }

    protected function transactionType(): TransactionType
    {
        return TransactionType::JournalVoucher;
    }
}
