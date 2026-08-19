<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Billing\CustomerDepositRegister;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Giro\GiroRegister;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Reporting\CustomerStatement;
use App\Domain\Reporting\Period;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The statement a customer gets before they pay.
 *
 * The property that matters is not the layout: it is that this closes on the
 * same figure the ageing report uses. A statement that disagrees with the
 * ageing report about one customer means somebody has to work out which of the
 * two lied, and by then neither is trusted.
 *
 * Credit exposure is the one figure that deliberately differs, and only by
 * deposits held — we cannot lose cash we are already holding, and no line on
 * this page is the one a held deposit reduced.
 */
class CustomerStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $finance;

    private CustomerStatement $statement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();

        $this->company = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);

        $this->statement = app(CustomerStatement::class);
    }

    public function test_it_closes_on_the_same_figure_the_credit_check_uses(): void
    {
        /*
         * The whole point. `forCompany()` is invoiced less paid less credited;
         * this lists those three things in date order, so the closing balance
         * is that subtraction done one row at a time. Two rules that happen to
         * agree today is not the same as one rule.
         *
         * The one thing that separates them is a deposit still held, which
         * exposure subtracts and a statement does not — see the pair of tests
         * below.
         */
        $this->invoice(30_000_000, '2026-08-05');
        $this->invoice(12_500_000, '2026-08-20');
        $this->pay(10_000_000, '2026-08-25');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-09-15'),
        );

        $expected = app(OutstandingReceivables::class)->forCompany($this->company);

        $this->assertSame($expected, $table->totals['saldo']);
        $this->assertSame(32_500_000, $table->totals['saldo']);
    }

    public function test_the_opening_balance_is_everything_before_the_window(): void
    {
        $this->invoice(20_000_000, '2026-07-10');
        $this->pay(5_000_000, '2026-07-20');
        $this->invoice(8_000_000, '2026-08-14');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $opening = $table->rows[0];

        $this->assertSame('Saldo awal', $opening['keterangan']);
        $this->assertSame(15_000_000, $opening['saldo']);
        $this->assertSame(23_000_000, $table->totals['saldo']);
    }

    public function test_a_movement_on_the_first_day_is_inside_the_window_not_before_it(): void
    {
        // Off-by-one on the boundary moves a figure between the opening
        // balance and the body, and the customer's bookkeeper spots it.
        $this->invoice(5_000_000, '2026-08-01');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(0, $table->rows[0]['saldo']);
        $this->assertCount(2, $table->rows);
        $this->assertSame(5_000_000, $table->rows[1]['tagihan']);
    }

    public function test_a_movement_on_the_last_day_is_inside_it_too(): void
    {
        $this->invoice(5_000_000, '2026-08-31');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertCount(2, $table->rows);
        $this->assertSame(5_000_000, $table->totals['saldo']);
    }

    public function test_charges_and_payments_are_separate_columns(): void
    {
        /*
         * This goes to somebody else's bookkeeper. A single signed column is
         * read wrongly at least once, and the reading that goes wrong is
         * always the one where they think they owe less.
         */
        $this->invoice(10_000_000, '2026-08-05');
        $this->pay(4_000_000, '2026-08-10');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        [$faktur, $bayar] = [$table->rows[1], $table->rows[2]];

        $this->assertSame(10_000_000, $faktur['tagihan']);
        $this->assertNull($faktur['pembayaran']);

        $this->assertSame(4_000_000, $bayar['pembayaran']);
        $this->assertNull($bayar['tagihan']);

        // And no figure on the statement is ever negative.
        foreach ($table->rows as $row) {
            $this->assertTrue(($row['tagihan'] ?? 0) >= 0);
            $this->assertTrue(($row['pembayaran'] ?? 0) >= 0);
        }
    }

    public function test_movements_are_in_date_order_whatever_order_they_were_entered(): void
    {
        $this->pay(1_000_000, '2026-08-28');
        $this->invoice(10_000_000, '2026-08-03');
        $this->pay(2_000_000, '2026-08-11');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $dates = array_map(
            fn (array $r) => $r['tanggal']?->toDateString(),
            array_slice($table->rows, 1),
        );

        $this->assertSame(['2026-08-03', '2026-08-11', '2026-08-28'], $dates);
    }

    public function test_a_reversed_payment_comes_back_as_a_charge(): void
    {
        /*
         * A transfer that bounced. Payment entries are append-only — the
         * reversal is a negative row, not an edit — so it lands on the
         * statement as an increase without any special case, which is exactly
         * what the customer needs to see.
         *
         * Note the window. A reversal is dated when it was made, not back on
         * the payment it undoes, so it falls in the month somebody noticed
         * rather than silently restating a month already sent out.
         */
        $ledger = app(PaymentLedger::class);

        $this->invoice(10_000_000, '2026-08-05');

        $entry = $ledger->recordManualPayment(
            company: $this->company,
            amountRupiah: 4_000_000,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse('2026-08-10'),
        );

        $ledger->reverse($entry, $this->finance, 'Transfer ditarik kembali');

        // August alone shows the payment and not the reversal — the reversal
        // happened in September.
        $agustus = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(6_000_000, $agustus->totals['saldo']);

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-09-30'),
        );

        $reversal = $table->rows[count($table->rows) - 1];

        $this->assertSame(4_000_000, $reversal['tagihan']);
        $this->assertStringContainsString('Pembalikan', $reversal['keterangan']);
        $this->assertSame(10_000_000, $table->totals['saldo']);
    }

    public function test_a_giro_is_noted_but_never_credited(): void
    {
        /*
         * The one place this decision meets a customer. They believe the
         * cheque paid the invoice; it has not cleared, so the invoice is open.
         * Crediting it would make the books claim money that is not in the
         * bank; omitting it entirely is what starts the argument.
         */
        $invoice = $this->invoice(20_000_000, '2026-08-05');

        app(GiroRegister::class)->receive(
            company: $this->company,
            nilaiRupiah: 20_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'AB123456',
            jatuhTempo: Carbon::parse('2026-10-04'),
            actor: $this->finance,
            invoice: $invoice,
            diterima: Carbon::parse('2026-08-06'),
        );

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        // Still owed in full.
        $this->assertSame(20_000_000, $table->totals['saldo']);

        $this->assertNotEmpty(array_filter(
            $table->catatan,
            fn (string $c) => str_contains($c, 'giro'),
        ));
    }

    public function test_a_deposit_still_held_is_noted_but_never_netted_off(): void
    {
        /*
         * The mirror of the giro rule, and it lands the other way round. A giro
         * is a promise we have not banked; a deposit is money we have. Neither
         * has been put against any invoice on this page, so neither reduces the
         * balance — but the customer needs to see we are holding theirs, or the
         * closing figure reads as money they still have to find.
         */
        $this->invoice(20_000_000, '2026-08-05');

        app(CustomerDepositRegister::class)->receive(
            company: $this->company,
            jumlahRupiah: 8_000_000,
            diterimaDi: PaidFrom::Bank,
            tanggal: Carbon::parse('2026-08-06'),
            actor: $this->finance,
        );

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(20_000_000, $table->totals['saldo']);

        $this->assertNotEmpty(array_filter(
            $table->catatan,
            fn (string $c) => str_contains($c, 'uang muka'),
        ));
    }

    public function test_credit_exposure_is_lower_than_the_statement_by_the_deposit(): void
    {
        // Both figures are right and they are not the same figure. The
        // statement says what these invoices come to; exposure says what we
        // stand to lose, and we cannot lose cash we are already holding.
        $this->invoice(20_000_000, '2026-08-05');

        app(CustomerDepositRegister::class)->receive(
            company: $this->company,
            jumlahRupiah: 8_000_000,
            diterimaDi: PaidFrom::Bank,
            tanggal: Carbon::parse('2026-08-06'),
            actor: $this->finance,
        );

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $exposure = app(OutstandingReceivables::class)->forCompany($this->company);

        $this->assertSame(12_000_000, $exposure);
        $this->assertSame($table->totals['saldo'] - 8_000_000, $exposure);
    }

    public function test_a_deposit_applied_to_an_invoice_shows_as_a_payment(): void
    {
        // Once it is against an invoice it is an ordinary settlement on this
        // page, because that is exactly what the customer experienced.
        $invoice = $this->invoice(20_000_000, '2026-08-05');

        $deposit = app(CustomerDepositRegister::class)->receive(
            company: $this->company,
            jumlahRupiah: 8_000_000,
            diterimaDi: PaidFrom::Bank,
            tanggal: Carbon::parse('2026-08-06'),
            actor: $this->finance,
        );

        app(CustomerDepositRegister::class)->apply(
            $deposit,
            $invoice,
            8_000_000,
            $this->finance,
            Carbon::parse('2026-08-10'),
        );

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(12_000_000, $table->totals['saldo']);

        // And the note is gone: nothing is being held any more.
        $this->assertEmpty(array_filter(
            $table->catatan,
            fn (string $c) => str_contains($c, 'uang muka'),
        ));
    }

    public function test_an_unmatched_payment_reduces_the_balance_and_says_so(): void
    {
        $this->invoice(10_000_000, '2026-08-05');
        $this->pay(3_000_000, '2026-08-09');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(7_000_000, $table->totals['saldo']);

        $this->assertNotEmpty(array_filter(
            $table->catatan,
            fn (string $c) => str_contains($c, 'belum dicocokkan'),
        ));
    }

    public function test_a_customer_who_has_paid_everything_gets_an_empty_statement_not_a_wrong_one(): void
    {
        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertCount(1, $table->rows);
        $this->assertSame(0, $table->totals['saldo']);
        $this->assertSame(0, $table->rows[0]['saldo']);
    }

    public function test_a_credit_note_reduces_what_they_owe(): void
    {
        $this->invoice(10_000_000, '2026-08-05');
        $this->creditNote(2_500_000, '2026-08-12');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $note = $table->rows[2];

        $this->assertSame(2_500_000, $note['pembayaran']);
        $this->assertNull($note['tagihan']);
        $this->assertSame(7_500_000, $table->totals['saldo']);
        $this->assertSame(
            app(OutstandingReceivables::class)->forCompany($this->company),
            $table->totals['saldo'],
        );
    }

    public function test_an_earlier_credit_note_is_in_the_opening_balance(): void
    {
        $this->invoice(10_000_000, '2026-07-05');
        $this->creditNote(4_000_000, '2026-07-20');

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(6_000_000, $table->rows[0]['saldo']);
    }

    public function test_a_draft_credit_note_is_not_given_away(): void
    {
        /*
         * A note somebody typed and nobody approved. Crediting it on a
         * statement hands the customer a reduction the books have not made,
         * and they will hold the paper to it.
         */
        $this->invoice(10_000_000, '2026-08-05');
        $this->creditNote(3_000_000, '2026-08-12', posted: false);

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(10_000_000, $table->totals['saldo']);
        $this->assertCount(2, $table->rows);
    }

    public function test_a_void_invoice_is_not_billed(): void
    {
        $this->invoice(10_000_000, '2026-08-05');

        $cancelled = $this->invoice(6_000_000, '2026-08-06');
        $cancelled->forceFill(['status' => Invoice::STATUS_VOID])->save();

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(10_000_000, $table->totals['saldo']);
        $this->assertCount(2, $table->rows);
    }

    public function test_one_customers_statement_never_carries_anothers_movements(): void
    {
        $other = Company::factory()->creditLimit(100_000_000)->create([
            'nama' => 'Bengkel Lain',
            'status' => Company::STATUS_ACTIVE,
        ]);

        $this->invoice(10_000_000, '2026-08-05');
        $this->invoice(7_000_000, '2026-08-06', $other);

        // Their payment must not appear here either — a statement that credits
        // one customer with another's transfer is the worst kind of wrong.
        app(PaymentLedger::class)->recordManualPayment(
            company: $other,
            amountRupiah: 5_000_000,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse('2026-08-15'),
        );

        $table = $this->statement->build(
            $this->company,
            Period::between('2026-08-01', '2026-08-31'),
        );

        $this->assertSame(10_000_000, $table->totals['saldo']);
        $this->assertCount(2, $table->rows);
    }

    // --- helpers ------------------------------------------------------------

    private function invoice(int $amount, string $date, ?Company $company = null): Invoice
    {
        return Invoice::factory()
            ->totalling($amount)
            ->create([
                'company_id' => ($company ?? $this->company)->id,
                'issued_on' => $date,
                'due_date' => Carbon::parse($date)->addDays(30)->toDateString(),
            ]);
    }

    private function creditNote(int $amount, string $date, bool $posted = true): CreditNote
    {
        $note = CreditNote::factory()->create([
            'company_id' => $this->company->id,
            'tanggal' => $date,
        ]);

        $note->forceFill([
            'subtotal_rupiah' => $amount,
            'ppn_rupiah' => 0,
            'total_rupiah' => $amount,
            'status' => $posted ? CreditNote::STATUS_POSTED : CreditNote::STATUS_DRAFT,
            'posted_at' => $posted ? Carbon::parse($date) : null,
        ])->save();

        return $note->refresh();
    }

    private function pay(int $amount, string $date): void
    {
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->company,
            amountRupiah: $amount,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse($date),
        );
    }
}
