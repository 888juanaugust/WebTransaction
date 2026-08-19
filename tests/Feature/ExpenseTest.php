<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\DocumentPoster;
use App\Domain\Accounting\Ledger;
use App\Domain\Expenses\ExpenseRecorder;
use App\Domain\Expenses\PaidFrom;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Recording money that went out on something other than goods.
 *
 * The gap this closed was quiet and large: `JournalDraft::manual()` existed
 * from the beginning and nothing ever called it, so rent, wages and fuel had
 * no way into the books. A laba rugi showing revenue and cost of sales against
 * almost no overhead is not conservative — it overstates the profit that tax
 * is calculated on.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private ExpenseRecorder $recorder;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->recorder = app(ExpenseRecorder::class);
        $this->ledger = app(Ledger::class);
    }

    public function test_an_expense_debits_the_cost_and_credits_the_pocket_it_left(): void
    {
        $expense = $this->record(AccountCode::BEBAN_SEWA, 12_000_000, PaidFrom::Bank);

        $this->assertSame(12_000_000, $this->ledger->balanceOf(AccountCode::BEBAN_SEWA));
        $this->assertSame(-12_000_000, $this->ledger->balanceOf(AccountCode::BANK));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::KAS));

        $this->assertStringStartsWith('BB-', $expense->nomor);
    }

    public function test_petty_cash_comes_out_of_kas_and_leaves_the_bank_alone(): void
    {
        /*
         * The reason PaidFrom exists. Kas has been in the chart since the
         * beginning with nothing ever posting to it, so cash spending would
         * have been booked against Bank — where it then fails to reconcile
         * against a statement that never mentioned it.
         */
        $this->record(AccountCode::BEBAN_PERLENGKAPAN, 350_000, PaidFrom::Kas);

        $this->assertSame(-350_000, $this->ledger->balanceOf(AccountCode::KAS));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::BANK));
    }

    public function test_recording_the_same_expense_twice_posts_it_twice(): void
    {
        /*
         * Deliberate, and the opposite of the webhook rule. Two identical
         * payments of Rp 350.000 for fuel on the same day are two real
         * payments — the system must not decide the second one is a duplicate.
         * Idempotency here is per document row, which is what protects against
         * a double-clicked button.
         */
        $this->record(AccountCode::BEBAN_KENDARAAN, 350_000, PaidFrom::Kas);
        $this->record(AccountCode::BEBAN_KENDARAAN, 350_000, PaidFrom::Kas);

        $this->assertSame(700_000, $this->ledger->balanceOf(AccountCode::BEBAN_KENDARAAN));
        $this->assertSame(2, JournalEntry::query()->where('jenis', JournalEntry::JENIS_BEBAN)->count());
    }

    public function test_posting_the_same_row_again_does_not(): void
    {
        // The double-clicked button. Same row, so the ledger refuses seconds.
        $expense = $this->record(AccountCode::BEBAN_GAJI, 5_000_000, PaidFrom::Bank);

        app(DocumentPoster::class)->expenseRecorded($expense, $this->finance);

        $this->assertSame(5_000_000, $this->ledger->balanceOf(AccountCode::BEBAN_GAJI));
        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_BEBAN)->count());
    }

    // ---------------------------------------------------- what it refuses

    public function test_cost_of_sales_cannot_be_typed_in_by_hand(): void
    {
        /*
         * The guard that matters most. HPP is derived entirely from stock
         * movements at the cost frozen on each one; a hand-entered debit puts
         * a figure into gross margin that no goods back, and the check proving
         * Persediaan equals what is on the shelves stops meaning anything.
         */
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/pergerakan stok/');

        $this->record(AccountCode::HARGA_POKOK_PENJUALAN, 1_000_000, PaidFrom::Bank);
    }

    public function test_the_purchase_price_variance_is_refused_for_the_same_reason(): void
    {
        // It hangs under the HPP header, so it is cost of sales too — and it
        // is written by the three-way match, not by a person.
        $this->expectException(DomainException::class);

        $this->record(AccountCode::SELISIH_HARGA_PEMBELIAN, 1_000_000, PaidFrom::Bank);
    }

    #[DataProvider('notExpenses')]
    public function test_only_an_expense_account_will_do(string $code): void
    {
        /*
         * Buying a vehicle is not a cost of August, it is an asset. Refusing
         * non-expense accounts is what stops this quietly becoming a
         * general-purpose journal entry form.
         */
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/bukan akun beban/');

        $this->record($code, 1_000_000, PaidFrom::Bank);
    }

    public static function notExpenses(): array
    {
        return [
            'aset' => [AccountCode::PERSEDIAAN],
            'kewajiban' => [AccountCode::UTANG_USAHA],
            'pendapatan' => [AccountCode::PENJUALAN],
            'modal' => [AccountCode::MODAL_DISETOR],
        ];
    }

    public function test_a_header_account_is_refused_even_though_it_is_an_expense(): void
    {
        /*
         * `6-0000 BEBAN OPERASIONAL` is the group heading, not a place money
         * goes. It passes the type check — it really is an expense — so it
         * needs its own guard, and without one the laba rugi would carry a
         * total that includes itself.
         */
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/akun induk/');

        $this->record('6-0000', 1_000_000, PaidFrom::Bank);
    }

    public function test_nothing_and_no_reason_are_both_refused(): void
    {
        $this->assertRefused(fn () => $this->record(AccountCode::BEBAN_SEWA, 0, PaidFrom::Bank));
        $this->assertRefused(fn () => $this->record(AccountCode::BEBAN_SEWA, -5_000, PaidFrom::Bank));
        $this->assertRefused(fn () => $this->recorder->record(
            now(), AccountCode::BEBAN_SEWA, PaidFrom::Bank, 1_000, '   ', $this->finance,
        ));
    }

    #[DataProvider('roles')]
    public function test_who_may_record_one(Role $role, bool $allowed): void
    {
        $actor = User::factory()->role($role)->create();

        try {
            $this->recorder->record(
                now(), AccountCode::BEBAN_SEWA, PaidFrom::Bank, 1_000_000, 'Sewa', $actor,
            );
            $this->assertTrue($allowed, "{$role->value} should not have been allowed");
        } catch (DomainException $e) {
            $this->assertFalse($allowed, $e->getMessage());
            $this->assertStringContainsString('tidak berhak', $e->getMessage());
        }
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            // Sales quote prices; they do not decide what the business spends.
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    // ---------------------------------------------------------- correcting

    public function test_a_mistake_is_reversed_rather_than_edited(): void
    {
        $expense = $this->record(AccountCode::BEBAN_SEWA, 12_000_000, PaidFrom::Bank);

        $reversal = $this->recorder->reverse($expense, $this->finance, 'Salah akun');

        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::BEBAN_SEWA));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::BANK));

        // Both rows stand. Neither the original nor the ledger line is gone.
        $this->assertSame(2, Expense::query()->count());
        $this->assertTrue($reversal->isReversal());
        $this->assertTrue($expense->refresh()->isReversed());
    }

    public function test_a_correction_is_dated_today_not_back_on_the_original(): void
    {
        /*
         * Back-dating it would move a figure in a month that may already be
         * closed and reported — and the period lock would refuse the entry,
         * turning a correction into an error nobody can clear.
         */
        $expense = $this->record(AccountCode::BEBAN_SEWA, 1_000_000, PaidFrom::Bank, '2026-06-15');

        $reversal = $this->recorder->reverse($expense, $this->finance, 'Salah nominal');

        $this->assertSame(now()->toDateString(), $reversal->tanggal->toDateString());
        $this->assertNotSame('2026-06-15', $reversal->tanggal->toDateString());
    }

    public function test_a_correction_cannot_itself_be_corrected_or_doubled(): void
    {
        $expense = $this->record(AccountCode::BEBAN_SEWA, 1_000_000, PaidFrom::Bank);
        $reversal = $this->recorder->reverse($expense, $this->finance, 'Salah akun');

        $this->assertRefused(fn () => $this->recorder->reverse($reversal, $this->finance, 'lagi'));
        $this->assertRefused(fn () => $this->recorder->reverse($expense->refresh(), $this->finance, 'lagi'));
    }

    public function test_a_correction_must_say_why(): void
    {
        $expense = $this->record(AccountCode::BEBAN_SEWA, 1_000_000, PaidFrom::Bank);

        $this->assertRefused(fn () => $this->recorder->reverse($expense, $this->finance, '  '));
    }

    public function test_an_expense_can_name_who_was_paid(): void
    {
        $supplier = Supplier::factory()->create(['nama' => 'PT Listrik Negara']);

        $expense = $this->recorder->record(
            now(), AccountCode::BEBAN_UTILITAS, PaidFrom::Bank, 2_400_000,
            'Listrik Agustus', $this->finance, supplier: $supplier, referensi: 'INV-PLN-8891',
        );

        $this->assertSame($supplier->id, $expense->supplier_id);
        $this->assertSame('INV-PLN-8891', $expense->referensi);
    }

    // --- helpers ------------------------------------------------------------

    private function record(
        string $code,
        int $amount,
        PaidFrom $from,
        string $tanggal = 'now',
    ): Expense {
        return $this->recorder->record(
            $tanggal === 'now' ? now() : Carbon::parse($tanggal),
            $code,
            $from,
            $amount,
            'Pembayaran uji',
            $this->finance,
        );
    }

    private function assertRefused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a DomainException.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }
}
