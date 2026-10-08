<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Filament\Support\Contracts\HasLabel;

/** How money moved, as the standard's payment forms list it. */
enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';
    case Edc = 'edc';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case Qris = 'qris';
    case PaymentLink = 'payment_link';
    case VirtualAccount = 'virtual_account';
    case EWallet = 'e_wallet';
    case OtherNonCash = 'other_non_cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Cheque => __('Cheque / giro'),
            self::BankTransfer => __('Bank transfer'),
            self::Edc => __('EDC'),
            self::DebitCard => __('Debit card'),
            self::CreditCard => __('Credit card'),
            self::Qris => __('QRIS'),
            self::PaymentLink => __('Payment link'),
            self::VirtualAccount => __('Virtual account'),
            self::EWallet => __('E-wallet'),
            self::OtherNonCash => __('Other non-cash'),
        };
    }

    public function isCheque(): bool
    {
        return $this === self::Cheque;
    }
}
