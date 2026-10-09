<?php

declare(strict_types=1);

namespace App\Client\Domain\Claims;

use App\Client\Domain\Teams\TeamAssigner;
use App\Client\Models\SettlementClaim;
use App\Client\Screens\CentralScreen;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\Auditor;
use App\Domain\Documents\PaymentMethod;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\AccountType;
use App\Models\GeneralLedger\Account;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pelunasan piutang on two keys: the customer's sales or marketing seat (or
 * the Owner) files that an invoice was paid, in whole or in part, and says
 * where, when and how; Finance verifies it into a sales receipt made in
 * Finance's name — the money is the actor, the receipt settles the invoice.
 */
final class SettlementClaims
{
    public function __construct(
        private readonly TwoKeys $twoKeys,
        private readonly ApprovalEngine $approvals,
        private readonly NumberGenerator $numbers,
        private readonly DocumentRepository $documents,
    ) {}

    public function file(SalesInvoice $invoice, int $amount, string $account, User $filer): SettlementClaim
    {
        $customer = $invoice->customer;
        if (! $filer->isAdministrator() && ! TeamAssigner::holdsSeat($filer, $customer)) {
            throw new RuntimeException(__('Only :name\'s sales or marketing seat, or an administrator, files a claim for this customer.', ['name' => $customer->name]));
        }
        if (! $this->approvals->isApproved($invoice)) {
            throw new RuntimeException(__(':number is not approved; nothing can be made from it yet.', ['number' => $invoice->number]));
        }
        $balance = $invoice->fresh()->balance();
        if ($balance <= 0) {
            throw new RuntimeException(__(':number has nothing left to settle.', ['number' => $invoice->number]));
        }
        if ($amount < 1 || $amount > $balance) {
            throw new RuntimeException(__('The amount must be between 1 and the balance of :number.', ['number' => $invoice->number]));
        }
        if (trim($account) === '') {
            throw new RuntimeException(__('Say where, when and how the money was handed over.'));
        }
        if (SettlementClaim::query()->where('sales_invoice_id', $invoice->id)->where('status', ClaimStatus::FILED)->exists()) {
            throw new RuntimeException(__(':number already has a claim awaiting verification.', ['number' => $invoice->number]));
        }

        $claim = SettlementClaim::query()->create([
            'customer_id' => $customer->id, 'sales_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
            'amount' => $amount, 'account' => trim($account), 'status' => ClaimStatus::FILED, 'filed_by' => $filer->id,
        ]);
        Auditor::log('claim_filed', $claim, null, ['invoice' => $invoice->number, 'amount' => $amount]);

        return $claim;
    }

    /** Finance's key: the receipt is made in the verifier's name and settles the invoice; the claim records the receipt. */
    public function verify(SettlementClaim $claim, User $actor, int $bankAccountId, string|CarbonImmutable $date, ?string $note = null): SalesReceipt
    {
        $this->twoKeys->assertMayDecide($claim, $actor, CentralScreen::SettlementClaims);
        $bank = Account::query()->ofType(AccountType::CashBank)->find($bankAccountId);
        if ($bank === null) {
            throw new RuntimeException(__('Choose the cash or bank account the money went to.'));
        }

        return DB::transaction(function () use ($claim, $actor, $bank, $date, $note): SalesReceipt {
            $claim = SettlementClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->twoKeys->assertMayDecide($claim, $actor, CentralScreen::SettlementClaims);
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($claim->sales_invoice_id);
            if ((int) $claim->amount > $invoice->balance()) {
                throw new RuntimeException(__('The balance of :number is now below the claim; the seat files a new one.', ['number' => $invoice->number]));
            }

            $date = CarbonImmutable::parse((string) ($date instanceof CarbonImmutable ? $date->toDateString() : $date));
            $series = $this->numbers->defaultSeries(TransactionType::CashBankVoucher, $actor)
                ?? throw new RuntimeException(__('No numbering series for receipts.'));
            $receipt = SalesReceipt::query()->create([
                'number' => $this->numbers->next($series, $date, $invoice->branch?->code),
                'series_id' => $series->id,
                'trans_date' => $date->toDateString(),
                'customer_id' => $invoice->customer_id,
                'branch_id' => $invoice->branch_id,
                'bank_account_id' => $bank->id,
                'payment_method' => self::methodFor($bank)->value,
                'amount' => 0,
                'use_credit' => false,
                'description' => __('Settlement claim #:id — :account', ['id' => $claim->id, 'account' => $claim->account]),
                'created_by' => $actor->id,
            ]);
            $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $invoice->id, 'amount' => (int) $claim->amount, 'discount' => 0]);
            $receipt->refreshTotal();
            $this->documents->created($receipt);

            $claim->forceFill(['status' => ClaimStatus::VERIFIED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'sales_receipt_id' => $receipt->id])->saveQuietly();
            Auditor::log('claim_verified', $claim, null, ['receipt' => $receipt->number, 'amount' => (int) $claim->amount]);

            return $receipt->fresh();
        });
    }

    public function reject(SettlementClaim $claim, User $actor, string $note): void
    {
        $this->twoKeys->reject($claim, $actor, CentralScreen::SettlementClaims, $note);
    }

    /** Cash when the account reads as a cash account, else a transfer; the account itself is Finance's choice. */
    public static function methodFor(Account $account): PaymentMethod
    {
        return preg_match('/\b(cash|kas)\b/i', (string) $account->name) ? PaymentMethod::Cash : PaymentMethod::BankTransfer;
    }
}
