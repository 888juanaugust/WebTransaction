<?php

declare(strict_types=1);

namespace App\Client\Domain\Claims;

use App\Client\Access\CentralGroups;
use App\Client\Models\ExpenseClaim;
use App\Client\Screens\CentralScreen;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\AccountType;
use App\Models\CashBank\CashPayment;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use App\Models\Sales\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A sales user's expenses on two keys: the sales user files what they spent,
 * on the road or for one of their customers; Finance verifies it into a
 * cash payment made in Finance's name, on the expense account and from the
 * cash or bank account Finance chooses.
 */
final class ExpenseClaims
{
    public function __construct(
        private readonly TwoKeys $twoKeys,
        private readonly NumberGenerator $numbers,
        private readonly DocumentRepository $documents,
    ) {}

    public function file(User $sales, ?Customer $customer, string $date, int $amount, string $description): ExpenseClaim
    {
        if (! CentralGroups::isMember($sales, CentralGroups::SALES)) {
            throw new RuntimeException(__('Only a member of the Sales group files an expense claim.'));
        }
        if ($customer !== null && (int) $customer->sales_user_id !== (int) $sales->id) {
            throw new RuntimeException(__(':name is not one of your customers.', ['name' => $customer->name]));
        }
        if ($amount <= 0) {
            throw new RuntimeException(__('The amount must be above zero.'));
        }
        $day = CarbonImmutable::parse($date);
        if ($day->isAfter(CarbonImmutable::today())) {
            throw new RuntimeException(__('The date cannot be in the future.'));
        }
        if (trim($description) === '') {
            throw new RuntimeException(__('Say what the money was spent on.'));
        }

        $claim = ExpenseClaim::query()->create([
            'sales_user_id' => $sales->id, 'customer_id' => $customer?->id,
            'branch_id' => $customer?->branch_id ?? Branch::defaultFor($sales)?->id,
            'trans_date' => $day->toDateString(), 'amount' => $amount, 'description' => trim($description),
            'status' => ClaimStatus::FILED, 'filed_by' => $sales->id,
        ]);
        Auditor::log('claim_filed', $claim, null, ['amount' => $amount, 'customer' => $customer?->name]);

        return $claim;
    }

    /** Finance's key: the cash payment is made in the verifier's name, today, on the chosen expense account. */
    public function verify(ExpenseClaim $claim, User $actor, int $expenseAccountId, int $bankAccountId, ?string $note = null): CashPayment
    {
        $this->twoKeys->assertMayDecide($claim, $actor, CentralScreen::ExpenseClaims);
        $expense = Account::query()->ofType(AccountType::Expense, AccountType::OtherExpense)->find($expenseAccountId)
            ?? throw new RuntimeException(__('Choose the expense account the claim is booked to.'));
        $bank = Account::query()->ofType(AccountType::CashBank)->find($bankAccountId)
            ?? throw new RuntimeException(__('Choose the cash or bank account the money comes from.'));

        return DB::transaction(function () use ($claim, $actor, $expense, $bank, $note): CashPayment {
            $claim = ExpenseClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->twoKeys->assertMayDecide($claim, $actor, CentralScreen::ExpenseClaims);

            $today = CarbonImmutable::today();
            $series = $this->numbers->defaultSeries(TransactionType::CashBankVoucher, $actor)
                ?? throw new RuntimeException(__('No numbering series for payments.'));
            $memo = $claim->description.($claim->customer ? ' — '.$claim->customer->name : '');
            $payment = CashPayment::query()->create([
                'number' => $this->numbers->next($series, $today, $claim->branch?->code),
                'series_id' => $series->id,
                'trans_date' => $today->toDateString(),
                'branch_id' => $claim->branch_id,
                'bank_account_id' => $bank->id,
                'payee' => $claim->salesUser?->name,
                'description' => __('Expense claim #:id of :name', ['id' => $claim->id, 'name' => $claim->salesUser?->name]),
                'amount' => 0,
                'created_by' => $actor->id,
            ]);
            $payment->lines()->create(['sort' => 0, 'account_id' => $expense->id, 'amount' => (int) $claim->amount, 'memo' => $memo, 'branch_id' => $claim->branch_id]);
            $payment->refreshTotal();
            $this->documents->created($payment);

            $claim->forceFill(['status' => ClaimStatus::VERIFIED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'cash_payment_id' => $payment->id])->saveQuietly();
            Auditor::log('claim_verified', $claim, null, ['payment' => $payment->number, 'amount' => (int) $claim->amount, 'account' => $expense->no]);

            return $payment->fresh();
        });
    }

    public function reject(ExpenseClaim $claim, User $actor, string $note): void
    {
        $this->twoKeys->reject($claim, $actor, CentralScreen::ExpenseClaims, $note);
    }

    /** The expense account a claim is booked to unless Finance picks another. */
    public static function defaultExpenseAccount(): ?Account
    {
        return Account::query()->where('no', (string) config('claims.expense_account', '6300'))->first();
    }
}
