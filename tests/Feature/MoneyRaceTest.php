<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Billing\CustomerDepositRegister;
use App\Domain\Credit\CreditChecker;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Giro\GiroRegister;
use App\Domain\Orders\CreditLimitExceededException;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierCreditNoteIssuer;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\Company;
use App\Models\CustomerDeposit;
use App\Models\Giro;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentEntry;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * The two money guards a single-threaded suite cannot tell from no guard.
 *
 * Every other test here runs one process. A check that reads a figure, decides
 * on it, and writes — with nothing holding that figure still in between —
 * passes every one of them and fails in production the first time two people
 * click at once. `StockReservationConcurrencyTest` already forks for the stock
 * row; these are the other two places where the same shape decides money.
 *
 * **The credit limit.** `confirmSingle` reads the customer's exposure, decides,
 * then reserves. The reservation locks the stock rows it touches, so two orders
 * for the same SKU queue behind each other — but orders on different SKUs share
 * no row, and different SKUs is the ordinary case for a customer with several
 * orders waiting. Measured: eight approvals released together against a
 * Rp 10.000.000 limit put five through and committed Rp 27.750.000, the limit
 * exceeded by 178%, with no error anywhere and every individual check correct
 * on the figures it was handed.
 *
 * **The invoice remainder.** `allocate` locks the payment entry, so two
 * applications of the *same* transfer queue up. It reads what the invoice still
 * owes without holding it, so two *different* transfers landing on one faktur
 * both saw the full remainder.
 *
 * In a business that sells on credit, the limit is the only thing standing
 * between the company and a bad debt, and the over-application refusal is what
 * stops a faktur going negative while the customer's other bills stand at full.
 * Both are worth a forked process to prove.
 *
 * DatabaseMigrations rather than RefreshDatabase, for the reason
 * `StockReservationConcurrencyTest` gives: RefreshDatabase wraps each test in a
 * transaction that is never committed, and a forked child on its own connection
 * would see an empty database.
 */
#[Group('concurrency')]
class MoneyRaceTest extends TestCase
{
    use DatabaseMigrations;

    private Warehouse $warehouse;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create();
        $this->owner = User::factory()->owner()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        // Forty distinct SKUs: eight orders of five lines, no two orders
        // sharing a stock row, so nothing serialises them by accident.
        foreach (range(1, 40) as $i) {
            $sku = 'RACE-'.$i;
            Product::factory()->create(['kode' => $sku, 'qty_per_ctn' => 1]);
            PriceListItem::factory()->create([
                'version_id' => $version->id, 'kode' => $sku, 'harga' => 1_000_000,
            ]);
            app(StockLedger::class)->record($sku, $this->warehouse->id, 100, MovementReason::Penerimaan);
        }
    }

    // --- the credit limit ----------------------------------------------------

    /**
     * One customer, one limit, eight people approving at once.
     *
     * Each order is Rp 5.550.000 against a Rp 10.000.000 limit, so exactly one
     * fits. The assertion is the invariant rather than the count: whatever gets
     * through must sit inside the limit.
     */
    public function test_simultaneous_approvals_cannot_spend_one_limit_twice(): void
    {
        $company = $this->customer(10_000_000);
        $orders = $this->eightOrdersFor($company);

        $outcomes = $this->race($orders, 'child_confirm');

        $this->assertSame(0, $outcomes['errored'], 'no unexpected failures');
        $this->assertSame(8, $outcomes['won'] + $outcomes['refused']);

        $status = app(CreditChecker::class)->status($company->refresh());
        $terpakai = $status->outstanding + $status->committed;

        $this->assertLessThanOrEqual(
            (int) $company->credit_limit_rupiah,
            $terpakai,
            'approvals may not commit more than the customer is allowed',
        );

        $this->assertSame(1, $outcomes['won'], 'exactly one order fits inside this limit');
    }

    /** A limit with room for three: three win, five are turned away. */
    public function test_a_wider_limit_admits_exactly_what_fits(): void
    {
        $company = $this->customer(17_000_000);
        $orders = $this->eightOrdersFor($company);

        $outcomes = $this->race($orders, 'child_confirm');

        $this->assertSame(0, $outcomes['errored']);

        $status = app(CreditChecker::class)->status($company->refresh());

        $this->assertLessThanOrEqual(
            (int) $company->credit_limit_rupiah,
            $status->outstanding + $status->committed,
        );
        $this->assertSame(3, $outcomes['won']);
    }

    /**
     * The lock must not serialise the whole business.
     *
     * Locking something broader than the customer — the orders table, a global
     * gate — would pass the tests above and quietly make every approval in the
     * company queue behind every other. Two customers approving at once have no
     * reason to wait for each other, and both must get through.
     */
    public function test_two_different_customers_do_not_block_each_other(): void
    {
        $a = $this->customer(10_000_000, 'A');
        $b = $this->customer(10_000_000, 'B');

        $orders = [
            $this->submittedOrder($a, ['RACE-1', 'RACE-2', 'RACE-3', 'RACE-4', 'RACE-5'], 1),
            $this->submittedOrder($b, ['RACE-6', 'RACE-7', 'RACE-8', 'RACE-9', 'RACE-10'], 2),
        ];

        $outcomes = $this->race($orders, 'child_confirm');

        $this->assertSame(2, $outcomes['won'], 'different customers spend different limits');
        $this->assertSame(0, $outcomes['errored']);
    }

    /**
     * A lock taken outside a transaction is released at once — a decoration
     * rather than a guard. The only caller is inside one; this refuses to let
     * that quietly stop being true.
     */
    public function test_the_credit_check_refuses_to_run_unprotected(): void
    {
        $company = $this->customer(10_000_000);
        $order = $this->submittedOrder($company, ['RACE-1'], 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/inside a transaction/');

        app(CreditChecker::class)->check($order->refresh());
    }

    // --- the invoice remainder ----------------------------------------------

    /**
     * Two transfers, one faktur, applied at the same instant.
     *
     * The refusal to over-apply is what keeps a faktur from going negative
     * while the customer's other bills stand at full and the remainder appears
     * in no queue. It has to hold when two people bank at once.
     */
    public function test_two_transfers_cannot_over_apply_one_faktur(): void
    {
        $company = $this->customer(900_000_000);
        $invoice = $this->openInvoiceFor($company, 10_000_000);

        // Four separate receipts, each big enough to cover the faktur alone.
        $entries = [];

        for ($n = 0; $n < 4; $n++) {
            $entries[] = app(PaymentLedger::class)->recordManualPayment(
                company: $company,
                amountRupiah: 10_000_000,
                actor: $this->finance,
                catatan: "Transfer {$n}",
            );
        }

        $outcomes = $this->race($entries, 'child_allocate', $invoice->id);

        $this->assertSame(0, $outcomes['errored']);
        $this->assertSame(1, $outcomes['won'], 'only one transfer may settle it');

        $fresh = Invoice::query()->findOrFail($invoice->id);

        $this->assertSame(0, $fresh->amountOutstanding(), 'settled exactly, not past zero');
        $this->assertGreaterThanOrEqual(
            0,
            $fresh->amountOutstanding(),
            'a faktur may never go negative',
        );
        $this->assertSame(10_000_000, $fresh->amountPaid());
    }

    /**
     * The mirror, and the one that costs money going out.
     *
     * Paying a supplier twice for one bill is the same defect facing the
     * other way — and unlike an over-applied faktur, which is a wrong number
     * until somebody notices, this is cash that has left the building.
     */
    public function test_two_payments_cannot_over_pay_one_supplier_bill(): void
    {
        $supplier = Supplier::factory()->create(['nama' => 'PT Pemasok Balapan']);

        $bill = SupplierBill::factory()->totalling(10_000_000)->create([
            'supplier_id' => $supplier->id,
            'status' => SupplierBill::STATUS_OPEN,
            'posted_at' => now(),
        ]);

        $entries = [];

        for ($n = 0; $n < 4; $n++) {
            $entries[] = app(SupplierLedger::class)->recordPayment(
                supplier: $supplier,
                amountRupiah: 10_000_000,
                actor: $this->finance,
                catatan: "Transfer keluar {$n}",
            );
        }

        $outcomes = $this->race($entries, 'child_pay_supplier', $bill->id);

        $this->assertSame(0, $outcomes['errored']);
        $this->assertSame(1, $outcomes['won'], 'only one payment may settle the bill');

        $fresh = SupplierBill::query()->findOrFail($bill->id);

        $this->assertSame(0, $fresh->amountOutstanding(), 'settled exactly, not past zero');
        $this->assertSame(10_000_000, $fresh->amountPaid());
    }

    // --- the rest of the money paths -----------------------------------------

    /**
     * Four deposits, one faktur, applied at the same instant.
     *
     * `apply()` locks the deposit — two people spending one deposit twice was
     * already thought about — and reads what the faktur owes without holding
     * it, which is the other half of the same question.
     */
    public function test_two_deposits_cannot_over_pay_one_faktur(): void
    {
        $company = $this->customer(900_000_000);
        $invoice = $this->openInvoiceFor($company, 10_000_000);

        $deposits = [];

        for ($n = 0; $n < 4; $n++) {
            $deposits[] = app(CustomerDepositRegister::class)->receive(
                company: $company,
                jumlahRupiah: 10_000_000,
                diterimaDi: PaidFrom::Bank,
                tanggal: now(),
                actor: $this->finance,
            );
        }

        $outcomes = $this->race($deposits, 'child_apply_deposit', $invoice->id);

        $this->assertSame(0, $outcomes['errored']);
        $this->assertSame(1, $outcomes['won'], 'only one deposit may settle it');

        $fresh = Invoice::query()->findOrFail($invoice->id);

        $this->assertSame(0, $fresh->amountOutstanding());
        $this->assertSame(10_000_000, $fresh->amountPaid());
    }

    /**
     * Four cheques on one faktur, clearing together.
     *
     * The property here is the opposite of the others and it matters more:
     * **every cheque must be recorded.** Clearing is the bank telling us money
     * arrived, and refusing to write that down leaves the reconciliation short
     * over a transfer nobody disputes — which is why clearing caps what it
     * applies and queues the rest rather than throwing.
     *
     * So all four win, the faktur takes what it owes and no more, and the
     * balance sits unallocated where the receipts queue finds it.
     */
    public function test_every_cheque_that_clears_is_recorded_and_none_over_applies(): void
    {
        $company = $this->customer(900_000_000);
        $invoice = $this->openInvoiceFor($company, 10_000_000);

        $giros = [];

        for ($n = 0; $n < 4; $n++) {
            $giros[] = app(GiroRegister::class)->receive(
                company: $company,
                nilaiRupiah: 10_000_000,
                bankPenerbit: 'BCA',
                nomorWarkat: 'AB'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                jatuhTempo: now()->addDays(30),
                actor: $this->finance,
                invoice: $invoice,
            );
        }

        $outcomes = $this->race($giros, 'child_clear_giro');

        $this->assertSame(0, $outcomes['errored'], 'a cheque that clears must never fail to record');
        $this->assertSame(4, $outcomes['won'], 'every cheque is recorded');

        $fresh = Invoice::query()->findOrFail($invoice->id);

        $this->assertSame(0, $fresh->amountOutstanding(), 'settled, and not past zero');
        $this->assertSame(10_000_000, $fresh->amountPaid(), 'the faktur took only what it owed');

        // The other three cheques are money in the bank, waiting to be matched.
        $this->assertSame(
            40_000_000,
            (int) PaymentEntry::query()->where('company_id', $company->id)->sum('amount_rupiah'),
            'all four cheques are on the ledger',
        );
    }

    /**
     * Four credit notes against one supplier bill.
     *
     * Nothing downstream catches this one: a supplier credit note is not a
     * payment, so it never reaches the allocation guard. Over-crediting a bill
     * writes off a debt we still owe.
     */
    public function test_two_credit_notes_cannot_over_credit_one_supplier_bill(): void
    {
        $supplier = Supplier::factory()->create(['nama' => 'PT Pemasok Nota']);

        $bill = SupplierBill::factory()->totalling(10_000_000)->create([
            'supplier_id' => $supplier->id,
            'status' => SupplierBill::STATUS_OPEN,
            'posted_at' => now(),
        ]);

        $notes = [];

        for ($n = 0; $n < 4; $n++) {
            $notes[] = app(SupplierCreditNoteIssuer::class)->draft(
                supplier: $supplier,
                tanggal: now(),
                accountCode: AccountCode::BEBAN_OPERASIONAL,
                dasarRupiah: 10_000_000,
                alasan: "Potongan harga {$n}",
                actor: $this->finance,
                bill: $bill,
            );
        }

        $outcomes = $this->race($notes, 'child_post_supplier_note');

        $this->assertSame(0, $outcomes['errored']);
        $this->assertSame(1, $outcomes['won'], 'only one note fits inside the bill');

        $fresh = SupplierBill::query()->findOrFail($bill->id);

        $this->assertGreaterThanOrEqual(
            0,
            $fresh->amountOutstanding(),
            'a bill may never be credited past zero',
        );
    }

    // --- fixtures ------------------------------------------------------------

    private function customer(int $limit, string $suffix = ''): Company
    {
        return Company::factory()->creditLimit($limit)->create([
            'nama' => 'Bengkel Balapan '.$suffix,
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    /** @return list<Order> */
    private function eightOrdersFor(Company $company): array
    {
        $orders = [];

        for ($n = 1; $n <= 8; $n++) {
            $skus = array_map(fn ($i) => 'RACE-'.(($n - 1) * 5 + $i), range(1, 5));
            $orders[] = $this->submittedOrder($company, $skus, $n);
        }

        return $orders;
    }

    /** @param  list<string>  $skus */
    private function submittedOrder(Company $company, array $skus, int $n): Order
    {
        $order = Order::factory()->create([
            'nomor' => "SO-RACE-{$company->id}-{$n}",
            'company_id' => $company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->owner->id,
        ]);

        foreach ($skus as $i => $sku) {
            OrderLine::factory()->qty(1)->create([
                'order_id' => $order->id, 'sku' => $sku, 'urutan' => $i + 1,
            ]);
        }

        app(OrderStateMachine::class)->submit($order->refresh(), $this->owner);

        return $order->refresh();
    }

    private function openInvoiceFor(Company $company, int $total): Invoice
    {
        return Invoice::factory()->totalling($total)->create([
            'company_id' => $company->id,
            'issued_on' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
        ]);
    }

    // --- the race itself -----------------------------------------------------

    /**
     * Fork one process per contender and start them together.
     *
     * The parent holds advisory lock 4245 exclusively before forking; each
     * child asks for it in shared mode and blocks. When the parent lets go
     * every child is granted at once, so they arrive within microseconds of
     * each other. Aligning on wall-clock time alone is too loose — the
     * processes often fail to overlap, and a race that does not race passes
     * against no lock at all.
     *
     * @param  list<object>  $subjects
     * @return array{won: int, refused: int, errored: int}
     */
    private function race(array $subjects, string $childMethod, ?int $context = null): array
    {
        $gate = DB::connection();
        $gate->select('SELECT pg_advisory_lock(4245)');

        $pids = [];

        foreach ($subjects as $subject) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $gate->select('SELECT pg_advisory_unlock(4245)');
                $this->fail('Could not fork a process for the race.');
            }

            if ($pid === 0) {
                exit($this->{$childMethod}((int) $subject->id, $context));
            }

            $pids[] = $pid;
        }

        usleep(400_000);
        $gate->select('SELECT pg_advisory_unlock(4245)');

        $out = ['won' => 0, 'refused' => 0, 'errored' => 0];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;

            match ($code) {
                0 => $out['won']++,
                1 => $out['refused']++,
                default => $out['errored']++,
            };
        }

        return $out;
    }

    /** Queue at the gate on a connection of this child's own. */
    private function atTheGate(): void
    {
        // The inherited connection belongs to the parent; sharing one socket
        // across processes corrupts both sides of the conversation.
        DB::purge();
        DB::reconnect();

        DB::select('SELECT pg_advisory_lock_shared(4245)');
        DB::select('SELECT pg_advisory_unlock_shared(4245)');
    }

    /** 0 = confirmed, 1 = refused on credit, anything else unexpected. */
    private function child_confirm(int $orderId, ?int $context): int
    {
        $this->atTheGate();

        try {
            $order = Order::findOrFail($orderId);
            app(OrderStateMachine::class)->confirm($order, User::findOrFail($order->created_by));

            return $order->refresh()->status === OrderStatus::Confirmed ? 0 : 3;
        } catch (CreditLimitExceededException) {
            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, "confirm child failed: {$e->getMessage()}\n");

            return 2;
        }
    }

    /** 0 = applied, 1 = refused, anything else unexpected. */
    private function child_apply_deposit(int $depositId, ?int $invoiceId): int
    {
        $this->atTheGate();

        try {
            app(CustomerDepositRegister::class)->apply(
                CustomerDeposit::findOrFail($depositId),
                Invoice::findOrFail($invoiceId),
                10_000_000,
                User::query()->where('role', Role::Finance->value)->firstOrFail(),
            );

            return 0;
        } catch (DomainException) {
            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, "deposit child failed: {$e->getMessage()}\n");

            return 2;
        }
    }

    /** 0 = recorded. A cheque that clears must never fail to be written down. */
    private function child_clear_giro(int $giroId, ?int $context): int
    {
        $this->atTheGate();

        try {
            app(GiroRegister::class)->clear(
                Giro::findOrFail($giroId),
                User::query()->where('role', Role::Finance->value)->firstOrFail(),
            );

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "giro child failed: {$e->getMessage()}\n");

            return 2;
        }
    }

    /** 0 = posted, 1 = refused as over-credit, anything else unexpected. */
    private function child_post_supplier_note(int $noteId, ?int $context): int
    {
        $this->atTheGate();

        try {
            app(SupplierCreditNoteIssuer::class)->post(
                SupplierCreditNote::findOrFail($noteId),
                User::query()->where('role', Role::Owner->value)->firstOrFail(),
            );

            return 0;
        } catch (DomainException) {
            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, "supplier note child failed: {$e->getMessage()}\n");

            return 2;
        }
    }

    /** 0 = paid, 1 = refused as over-payment, anything else unexpected. */
    private function child_pay_supplier(int $entryId, ?int $billId): int
    {
        $this->atTheGate();

        try {
            app(SupplierLedger::class)->allocate(
                SupplierPaymentEntry::findOrFail($entryId),
                SupplierBill::findOrFail($billId),
                10_000_000,
                User::query()->where('role', Role::Finance->value)->firstOrFail(),
            );

            return 0;
        } catch (DomainException) {
            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, "supplier child failed: {$e->getMessage()}\n");

            return 2;
        }
    }

    /** 0 = allocated, 1 = refused as over-application, anything else unexpected. */
    private function child_allocate(int $entryId, ?int $invoiceId): int
    {
        $this->atTheGate();

        try {
            app(PaymentLedger::class)->allocate(
                PaymentEntry::findOrFail($entryId),
                Invoice::findOrFail($invoiceId),
                10_000_000,
                User::query()->where('role', Role::Finance->value)->firstOrFail(),
            );

            return 0;
        } catch (DomainException) {
            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, "allocate child failed: {$e->getMessage()}\n");

            return 2;
        }
    }
}
