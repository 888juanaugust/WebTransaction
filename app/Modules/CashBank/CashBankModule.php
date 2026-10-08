<?php

declare(strict_types=1);

namespace App\Modules\CashBank;

use App\Domain\Access\MenuKey;
use App\Domain\Approval\ApprovalType;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\Blockers\GiroBlocker;
use App\Domain\Posting\Blockers\ReconciledBlocker;
use App\Models\CashBank\BankReconciliation;
use App\Models\CashBank\BankReconciliationItem;
use App\Models\CashBank\BankStatement;
use App\Models\CashBank\BankStatementLine;
use App\Models\CashBank\BankTransfer;
use App\Models\CashBank\BankTransferFee;
use App\Models\CashBank\CashPayment;
use App\Models\CashBank\CashPaymentLine;
use App\Models\CashBank\CashReceipt;
use App\Models\CashBank\CashReceiptLine;
use App\Models\CashBank\Giro;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/** Cash & Bank: payments, receipts, transfers, statements, reconciliation and giros. Core. */
final class CashBankModule extends BaseModule
{
    public static function key(): string
    {
        return 'cash-bank';
    }

    public static function menuKeys(): array
    {
        return [
            MenuKey::Payments, MenuKey::Receipts, MenuKey::BankTransfers, MenuKey::BankStatements, MenuKey::BankBook, MenuKey::BankReconciliation,
            MenuKey::InternetBanking, MenuKey::VirtualAccounts, MenuKey::EPayment,
        ];
    }

    public static function morphMap(): array
    {
        return [
            'cash_payment' => CashPayment::class,
            'cash_payment_line' => CashPaymentLine::class,
            'cash_receipt' => CashReceipt::class,
            'cash_receipt_line' => CashReceiptLine::class,
            'bank_transfer' => BankTransfer::class,
            'bank_transfer_fee' => BankTransferFee::class,
            'bank_statement' => BankStatement::class,
            'bank_statement_line' => BankStatementLine::class,
            'bank_reconciliation' => BankReconciliation::class,
            'bank_reconciliation_item' => BankReconciliationItem::class,
            'giro' => Giro::class,
        ];
    }

    public static function boot(ModuleContext $context): void
    {
        // A reconciled bank line, or a giro the bank decided, locks its document.
        $context->guard->addBlocker($context->app->make(ReconciledBlocker::class));
        $context->guard->addBlocker($context->app->make(GiroBlocker::class));

        // The documents that may wait for approval, under the transaction type approval rules name them by.
        $context->approvals->register(new ApprovalType(CashPayment::class, TransactionType::CashBankVoucher));
        $context->approvals->register(new ApprovalType(CashReceipt::class, TransactionType::CashBankVoucher));
        $context->approvals->register(new ApprovalType(BankTransfer::class, TransactionType::BankTransfer));
    }
}
