<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\DocumentPoster;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\TrialBalance;
use App\Domain\Billing\CustomerDepositRegister;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Credit\CreditChecker;
use App\Domain\Expenses\PaidFrom;
use App\Models\Company;
use App\Models\CustomerDeposit;
use App\Models\CustomerDepositMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Money in before anything is owed.
 *
 * The property under test is not that a deposit can be recorded — an
 * unallocated payment could always do that. It is that a deposit lands on the
 * liability side of the balance sheet instead of as a negative receivable, and
 * that the two figures which describe a customer — what they owe and what we
 * are holding of theirs — stay separately true through every step.
 */
class CustomerDepositTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $finance;

    private CustomerDepositRegister $register;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->company = Company::factory()->creditLimit(100_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);

        $this->register = app(CustomerDepositRegister::class);
    }

    // --- receiving ----------------------------------------------------------

    public function test_a_deposit_is_a_liability_not_a_negative_receivable(): void
    {
        /*
         * The whole reason this document exists. Booked as an unallocated
         * payment the same ten million would sit in Piutang Usaha as minus
         * ten million: the balance sheet would say customers owe us less than
         * they do and that we owe nobody anything, when we are holding their
         * cash and have shipped nothing.
         */
        $this->receive(10_000_000);

        $trial = TrialBalance::asOf(Carbon::parse('2026-09-15'));

        $this->assertSame(10_000_000, $this->balance($trial, AccountCode::UANG_MUKA_PELANGGAN));
        $this->assertSame(0, $this->balance($trial, AccountCode::PIUTANG_USAHA));
        $this->assertSame(10_000_000, $this->balance($trial, AccountCode::BANK));
    }

    public function test_cash_over_the_counter_lands_in_kas_not_bank(): void
    {
        // The customer who has no account yet is the same customer who is asked
        // for a deposit, so cash is the common case rather than the exotic one.
        $this->receive(4_000_000, PaidFrom::Kas);

        $trial = TrialBalance::asOf(Carbon::parse('2026-09-15'));

        $this->assertSame(4_000_000, $this->balance($trial, AccountCode::KAS));
        $this->assertSame(0, $this->balance($trial, AccountCode::BANK));
    }

    public function test_it_can_be_taken_against_an_order_or_against_nothing(): void
    {
        $order = Order::factory()->create(['company_id' => $this->company->id]);

        $tied = $this->receive(5_000_000, order: $order);
        $loose = $this->receive(3_000_000);

        $this->assertSame($order->id, $tied->order_id);
        $this->assertNull($loose->order_id);
    }

    public function test_a_deposit_cannot_be_pinned_to_another_customers_order(): void
    {
        $other = Order::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->expectException(DomainException::class);
        $this->receive(5_000_000, order: $other);
    }

    #[DataProvider('impossibleAmounts')]
    public function test_a_deposit_must_be_a_real_amount(int $amount): void
    {
        $this->expectException(DomainException::class);
        $this->receive($amount);
    }

    public static function impossibleAmounts(): array
    {
        return ['nol' => [0], 'negatif' => [-5_000_000]];
    }

    public function test_a_deposit_cannot_be_dated_in_the_future(): void
    {
        $this->expectException(DomainException::class);

        $this->register->receive(
            company: $this->company,
            jumlahRupiah: 5_000_000,
            diterimaDi: PaidFrom::Bank,
            tanggal: Carbon::parse('2026-09-16'),
            actor: $this->finance,
        );
    }

    #[DataProvider('rolesWhoMayNot')]
    public function test_only_finance_may_take_a_deposit(Role $role): void
    {
        /*
         * Behind the confirm-payment permission, and therefore the same
         * separation: whoever agreed the price must not also be the one who
         * says the money arrived.
         */
        $this->expectException(DomainException::class);

        $this->register->receive(
            company: $this->company,
            jumlahRupiah: 5_000_000,
            diterimaDi: PaidFrom::Bank,
            tanggal: Carbon::parse('2026-09-01'),
            actor: User::factory()->role($role)->create(),
        );
    }

    public static function rolesWhoMayNot(): array
    {
        return ['sales' => [Role::Sales], 'gudang' => [Role::Warehouse]];
    }

    // --- applying -----------------------------------------------------------

    public function test_applying_it_moves_the_liability_into_the_receivable(): void
    {
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(6_000_000);

        $this->register->apply($deposit, $invoice, 6_000_000, $this->finance);

        $trial = TrialBalance::asOf(Carbon::parse('2026-09-15'));

        $this->assertSame(4_000_000, $this->balance($trial, AccountCode::UANG_MUKA_PELANGGAN));
        $this->assertSame(0, $this->balance($trial, AccountCode::PIUTANG_USAHA));
    }

    public function test_applying_it_moves_no_cash(): void
    {
        /*
         * The money was banked when the deposit was taken. An application that
         * touched Bank would invent a receipt that never happened and leave the
         * bank reconciliation hunting for a transfer nobody made.
         */
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(6_000_000);

        $before = $this->balance(
            TrialBalance::asOf(Carbon::parse('2026-09-15')),
            AccountCode::BANK,
        );

        $this->register->apply($deposit, $invoice, 6_000_000, $this->finance);

        $after = $this->balance(
            TrialBalance::asOf(Carbon::parse('2026-09-15')),
            AccountCode::BANK,
        );

        $this->assertSame($before, $after);
    }

    public function test_an_application_settles_the_invoice_it_covers(): void
    {
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(6_000_000);

        $this->register->apply($deposit, $invoice, 6_000_000, $this->finance);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->amountOutstanding());
    }

    public function test_a_part_application_leaves_the_invoice_open(): void
    {
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(6_000_000);

        $this->register->apply($deposit, $invoice, 2_000_000, $this->finance);

        $this->assertSame(Invoice::STATUS_OPEN, $invoice->refresh()->status);
        $this->assertSame(4_000_000, $invoice->amountOutstanding());
    }

    public function test_one_deposit_can_settle_several_invoices(): void
    {
        // The ordinary case for an order that ships in drops.
        $deposit = $this->receive(10_000_000);

        $this->register->apply($deposit, $this->invoice(3_000_000), 3_000_000, $this->finance);
        $this->register->apply($deposit, $this->invoice(4_000_000), 4_000_000, $this->finance);

        $this->assertSame(7_000_000, (int) $deposit->refresh()->terpakai_rupiah);
        $this->assertSame(3_000_000, $deposit->sisaRupiah());
        $this->assertTrue($deposit->isHeld());
    }

    public function test_spending_the_last_of_it_closes_the_deposit(): void
    {
        $deposit = $this->receive(6_000_000);

        $this->register->apply($deposit, $this->invoice(6_000_000), 6_000_000, $this->finance);

        $this->assertSame(CustomerDeposit::STATUS_CLOSED, $deposit->refresh()->status);
        $this->assertSame(0, $deposit->sisaRupiah());
    }

    public function test_it_cannot_be_spent_beyond_what_it_holds(): void
    {
        // Otherwise a typo spends money nobody paid, and the liability account
        // goes negative with a document behind it that looks legitimate.
        $deposit = $this->receive(5_000_000);

        $this->expectException(DomainException::class);
        $this->register->apply($deposit, $this->invoice(9_000_000), 6_000_000, $this->finance);
    }

    public function test_it_cannot_overpay_an_invoice(): void
    {
        /*
         * Overpaying pushes Piutang Usaha negative for that customer — the
         * exact condition this document exists to prevent. Refusing here is
         * cheaper than explaining a negative receivable later.
         */
        $deposit = $this->receive(10_000_000);

        $this->expectException(DomainException::class);
        $this->register->apply($deposit, $this->invoice(4_000_000), 6_000_000, $this->finance);
    }

    public function test_what_an_invoice_already_paid_is_counted_before_the_deposit_is(): void
    {
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(6_000_000);

        PaymentEntry::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount_rupiah' => 5_000_000,
            'kind' => PaymentEntry::KIND_PAYMENT,
            'actor_id' => $this->finance->id,
            'paid_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->register->apply($deposit, $invoice, 2_000_000, $this->finance);
    }

    public function test_a_deposit_cannot_be_applied_to_another_customers_invoice(): void
    {
        $deposit = $this->receive(10_000_000);

        $theirs = Invoice::factory()->totalling(5_000_000)->create([
            'company_id' => Company::factory()->create()->id,
            'issued_on' => '2026-09-01',
            'due_date' => '2026-10-01',
        ]);

        $this->expectException(DomainException::class);
        $this->register->apply($deposit, $theirs, 5_000_000, $this->finance);
    }

    public function test_a_void_invoice_takes_no_deposit(): void
    {
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(5_000_000);
        $invoice->forceFill(['status' => Invoice::STATUS_VOID])->save();

        $this->expectException(DomainException::class);
        $this->register->apply($deposit, $invoice, 5_000_000, $this->finance);
    }

    public function test_an_application_is_a_payment_entry_of_its_own_kind(): void
    {
        /*
         * A payment entry, so the invoice, the ageing report, the statement and
         * the portal all see it without being taught about a second table. Its
         * own kind, so the journal can say no cash moved.
         */
        $deposit = $this->receive(10_000_000);
        $invoice = $this->invoice(6_000_000);

        $movement = $this->register->apply($deposit, $invoice, 6_000_000, $this->finance);
        $payment = $movement->paymentEntry;

        $this->assertSame(PaymentEntry::KIND_DEPOSIT_APPLICATION, $payment->kind);
        $this->assertSame($invoice->id, $payment->invoice_id);

        // And it must never show up in the unmatched-payments worklist, where
        // somebody would try to allocate it a second time.
        $this->assertSame(0, PaymentEntry::query()->unmatched()->count());
    }

    // --- refunding ----------------------------------------------------------

    public function test_a_refund_puts_the_money_back_out_of_the_bank(): void
    {
        $deposit = $this->receive(10_000_000);

        $this->register->refund($deposit, 10_000_000, $this->finance, 'Pesanan dibatalkan');

        $trial = TrialBalance::asOf(Carbon::parse('2026-09-15'));

        $this->assertSame(0, $this->balance($trial, AccountCode::UANG_MUKA_PELANGGAN));
        $this->assertSame(0, $this->balance($trial, AccountCode::BANK));
        $this->assertSame(CustomerDeposit::STATUS_CLOSED, $deposit->refresh()->status);
    }

    public function test_a_refund_is_a_second_movement_not_a_cancelled_first_one(): void
    {
        /*
         * The money genuinely came in and genuinely went out, and the bank
         * statement will show both. Reversing the receipt instead would leave
         * the reconciliation two movements short with nothing to explain them.
         */
        $deposit = $this->receive(10_000_000);
        $this->register->refund($deposit, 10_000_000, $this->finance, 'Pesanan dibatalkan');

        $this->assertSame(1, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_UANG_MUKA)->count());
        $this->assertSame(1, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_UANG_MUKA_KEMBALI)->count());
        $this->assertSame(0, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_PEMBALIKAN)->count());
    }

    public function test_only_what_is_left_can_be_refunded(): void
    {
        $deposit = $this->receive(10_000_000);
        $this->register->apply($deposit, $this->invoice(7_000_000), 7_000_000, $this->finance);

        $this->expectException(DomainException::class);
        $this->register->refund($deposit, 5_000_000, $this->finance, 'Sisanya dikembalikan');
    }

    public function test_a_refund_must_say_why(): void
    {
        // Money leaving the business with no stated reason is the one thing an
        // auditor will always ask about.
        $deposit = $this->receive(10_000_000);

        $this->expectException(DomainException::class);
        $this->register->refund($deposit, 5_000_000, $this->finance, '   ');
    }

    // --- what it does to credit --------------------------------------------

    public function test_holding_a_deposit_reduces_what_a_customer_can_lose_us(): void
    {
        /*
         * The contrast with giro is the point. A giro is a promise with a date
         * on it that can bounce, so it does not free credit. A deposit is money
         * already in our account — we cannot lose cash we are holding.
         */
        $this->invoice(40_000_000);

        $this->assertSame(60_000_000, app(CreditChecker::class)->available($this->company));

        $this->receive(10_000_000);

        $this->assertSame(70_000_000, app(CreditChecker::class)->available($this->company));
    }

    public function test_applying_a_deposit_changes_nothing_about_exposure(): void
    {
        // What they owe is the same before and after: it has merely stopped
        // being covered by a deposit and started being a settled invoice.
        $this->receive(10_000_000);
        $invoice = $this->invoice(40_000_000);

        $before = app(OutstandingReceivables::class)->forCompany($this->company);

        $this->register->apply(
            CustomerDeposit::query()->sole(),
            $invoice,
            10_000_000,
            $this->finance,
        );

        $this->assertSame($before, app(OutstandingReceivables::class)->forCompany($this->company));
    }

    public function test_a_refund_puts_the_exposure_back(): void
    {
        $this->invoice(40_000_000);
        $deposit = $this->receive(10_000_000);

        $this->register->refund($deposit, 10_000_000, $this->finance, 'Batal');

        $this->assertSame(60_000_000, app(CreditChecker::class)->available($this->company));
    }

    public function test_one_customers_deposit_frees_nobody_elses_credit(): void
    {
        $other = Company::factory()->creditLimit(100_000_000)->create([
            'status' => Company::STATUS_ACTIVE,
        ]);

        $this->receive(10_000_000);

        $this->assertSame(100_000_000, app(CreditChecker::class)->available($other));
    }

    // --- what it does to the books -----------------------------------------

    public function test_the_control_account_ties_through_every_step(): void
    {
        $deposit = $this->receive(10_000_000);
        $this->assertTiedUp();

        $this->register->apply($deposit, $this->invoice(6_000_000), 6_000_000, $this->finance);
        $this->assertTiedUp();

        $this->register->refund($deposit, 4_000_000, $this->finance, 'Sisanya dikembalikan');
        $this->assertTiedUp();
    }

    public function test_the_cached_totals_are_reconstructible_from_the_movements(): void
    {
        /*
         * Invariant 1, applied to money the same way it applies to stock: the
         * cached figure is a convenience, and the movements are the truth.
         */
        $deposit = $this->receive(10_000_000);
        $this->register->apply($deposit, $this->invoice(3_000_000), 3_000_000, $this->finance);
        $this->register->refund($deposit, 2_000_000, $this->finance, 'Kelebihan setoran');

        $deposit->refresh();

        $summed = fn (string $jenis) => (int) CustomerDepositMovement::query()
            ->where('customer_deposit_id', $deposit->id)
            ->where('jenis', $jenis)
            ->sum('jumlah_rupiah');

        $this->assertSame($summed(CustomerDepositMovement::JENIS_PAKAI), (int) $deposit->terpakai_rupiah);
        $this->assertSame($summed(CustomerDepositMovement::JENIS_KEMBALI), (int) $deposit->dikembalikan_rupiah);
        $this->assertSame(5_000_000, $deposit->sisaRupiah());
    }

    public function test_a_deposit_closed_with_money_still_on_it_would_still_be_counted(): void
    {
        /*
         * The status column is a convenience; the arithmetic is the truth.
         * Nothing today can close a deposit that still holds money — the
         * register writes both figures in one transaction — but the control
         * account is proved against this, and a proof that trusts a cached
         * flag only shows the flag agrees with itself.
         *
         * So the status is corrupted deliberately here and the figure must not
         * move.
         */
        $deposit = $this->receive(10_000_000);

        $deposit->forceFill(['status' => CustomerDeposit::STATUS_CLOSED])->save();

        $this->assertSame(
            10_000_000,
            app(OutstandingReceivables::class)->depositsHeld($this->company),
        );
        $this->assertTiedUp();
    }

    public function test_taking_a_deposit_leaves_piutang_usaha_alone(): void
    {
        // Which is why total() must not subtract deposits held: nothing about
        // taking one touches the account that figure proves.
        $this->invoice(40_000_000);
        $before = app(OutstandingReceivables::class)->total();

        $this->receive(10_000_000);

        $this->assertSame($before, app(OutstandingReceivables::class)->total());
        $this->assertTiedUp();
    }

    // --- helpers ------------------------------------------------------------

    private function receive(
        int $amount,
        PaidFrom $into = PaidFrom::Bank,
        ?Order $order = null,
    ): CustomerDeposit {
        return $this->register->receive(
            company: $this->company,
            jumlahRupiah: $amount,
            diterimaDi: $into,
            tanggal: Carbon::parse('2026-09-01'),
            actor: $this->finance,
            order: $order,
            referensi: 'TRF-0001',
        );
    }

    /**
     * An invoice with its journal posted.
     *
     * Posted rather than left as a bare factory row, because half these tests
     * assert the control accounts tie — and an invoice the ledger has never
     * heard of puts Piutang Usaha out before the deposit does anything.
     */
    private function invoice(int $amount): Invoice
    {
        $invoice = Invoice::factory()->totalling($amount)->create([
            'company_id' => $this->company->id,
            'issued_on' => '2026-09-02',
            'due_date' => '2026-10-02',
        ]);

        app(DocumentPoster::class)->invoiceIssued($invoice, $this->finance);

        return $invoice;
    }

    /** The account's balance in its own normal direction, so every figure is positive. */
    private function balance(TrialBalance $trial, string $kode): int
    {
        foreach ($trial->rows() as $row) {
            if ($row->account->kode === $kode) {
                return $row->balance();
            }
        }

        return 0;
    }

    private function assertTiedUp(): void
    {
        foreach (app(LedgerReconciliation::class)->checks() as $check) {
            $this->assertSame(
                0,
                $check->selisih(),
                "{$check->nama} tidak cocok dengan buku pembantunya.",
            );
        }
    }
}
