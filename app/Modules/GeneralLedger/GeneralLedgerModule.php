<?php

declare(strict_types=1);

namespace App\Modules\GeneralLedger;

use App\Domain\Access\MenuKey;
use App\Domain\Approval\ApprovalType;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\Blockers\ReferencedBlocker;
use App\Domain\Posting\Blockers\SettledBlocker;
use App\Domain\Settlement\AllocationLedger;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\AccountingPeriod;
use App\Models\GeneralLedger\AccountOpeningBalance;
use App\Models\GeneralLedger\DocumentRevision;
use App\Models\GeneralLedger\ExpenseAccrual;
use App\Models\GeneralLedger\JournalEntry;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\GeneralLedger\Posting;
use App\Models\Settlement\PaymentAllocation;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/**
 * General Ledger: the chart of accounts, the posting layer and its ledgers,
 * the settlement ledger, periods and revisions. Core.
 */
final class GeneralLedgerModule extends BaseModule
{
    public static function key(): string
    {
        return 'general-ledger';
    }

    public static function menuKeys(): array
    {
        return [MenuKey::ChartOfAccounts, MenuKey::ExpenseAccruals, MenuKey::JournalVouchers, MenuKey::AccountHistory, MenuKey::JournalActivityLog];
    }

    public static function morphMap(): array
    {
        return [
            'account' => Account::class,
            'posting' => Posting::class,
            'journal_entry' => JournalEntry::class,
            'document_revision' => DocumentRevision::class,
            'accounting_period' => AccountingPeriod::class,
            'account_opening_balance' => AccountOpeningBalance::class,
            'journal_voucher' => JournalVoucher::class,
            'expense_accrual' => ExpenseAccrual::class,
            'payment_allocation' => PaymentAllocation::class,
        ];
    }

    public static function boot(ModuleContext $context): void
    {
        // The settlement ledger writes what each payment applied to which invoice.
        $context->postings->extend(fn ($posting, $builder) => $context->app->make(AllocationLedger::class)->write($posting, $builder));
        $context->postings->onUnpost(fn ($posting) => $context->app->make(AllocationLedger::class)->unwrite($posting));

        // What keeps a document from changing: payments applied to it, documents made from it.
        $context->guard->addBlocker($context->app->make(SettledBlocker::class));
        $context->guard->addBlocker($context->app->make(ReferencedBlocker::class));

        // The documents that may wait for approval, under the transaction type approval rules name them by.
        $context->approvals->register(new ApprovalType(JournalVoucher::class, TransactionType::JournalVoucher));
        $context->approvals->register(new ApprovalType(ExpenseAccrual::class, TransactionType::ExpenseAccrual));
    }
}
