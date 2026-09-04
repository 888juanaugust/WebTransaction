<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Payments\PaymentLedger;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentEntry;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One transfer, several fakturs — and money that is never applied twice.
 *
 * Payment settlement is one of the five areas CLAUDE.md names as where bugs
 * cost money, and the bug this file exists for was live: allocating a
 * Rp 50.000.000 payment to a Rp 12.000.000 faktur marked that faktur paid,
 * left it at a **negative** outstanding, left the customer's other fakturs at
 * their full amount, and dropped the entry out of the unmatched queue. Nothing
 * anywhere showed the remaining Rp 38.000.000.
 *
 * The rules being defended:
 *
 * 1. An entry is money arriving; an allocation is what it settles. One entry
 *    per bank line, however many bills it covers.
 * 2. Neither side can be over-applied — not the money, not the bill.
 * 3. Allocations are append-only. Taking one back is a negative row, and the
 *    faktur reopens.
 * 4. Reversing a payment takes back every application it made, not just the
 *    one invoice its entry happened to name.
 */
class PaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->finance = User::factory()->finance()->create();
        $this->company = Company::factory()->creditLimit(500_000_000)->create();
    }

    private function invoice(int $amount, string $due = '2026-10-02'): Invoice
    {
        $invoice = Invoice::factory()->totalling($amount)->create([
            'company_id' => $this->company->id,
            'issued_on' => '2026-09-02',
            'due_date' => $due,
        ]);

        app(DocumentPoster::class)->invoiceIssued($invoice, $this->finance);

        return $invoice;
    }

    private function ledger(): PaymentLedger
    {
        return app(PaymentLedger::class);
    }

    // --- the bug this file exists for -------------------------------------

    public function test_a_payment_cannot_be_applied_beyond_what_a_faktur_owes(): void
    {
        $kecil = $this->invoice(12_000_000);
        $besar = $this->invoice(30_000_000);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 50_000_000, actor: $this->finance,
        );

        try {
            $this->ledger()->allocate($entry, $kecil, 50_000_000, $this->finance);
            $this->fail('Over-applying a payment to one faktur must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Rp 12.000.000', $e->getMessage());
            $this->assertStringContainsString('Rp 50.000.000', $e->getMessage());
        }

        // Nothing moved: no faktur went negative, none was marked paid, and
        // every rupiah is still visibly unaccounted for.
        $this->assertSame(12_000_000, $kecil->fresh()->amountOutstanding());
        $this->assertSame(30_000_000, $besar->fresh()->amountOutstanding());
        $this->assertSame(Invoice::STATUS_OPEN, $kecil->fresh()->status);
        $this->assertSame(50_000_000, $this->ledger()->unallocated($entry));
    }

    public function test_one_transfer_settles_several_fakturs(): void
    {
        $satu = $this->invoice(12_000_000, '2026-09-20');
        $dua = $this->invoice(30_000_000, '2026-09-25');
        $tiga = $this->invoice(20_000_000, '2026-10-05');

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company,
            amountRupiah: 50_000_000,
            actor: $this->finance,
            spread: [[$satu, 12_000_000], [$dua, 30_000_000], [$tiga, 8_000_000]],
        );

        $this->assertSame(Invoice::STATUS_PAID, $satu->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $dua->fresh()->status);
        $this->assertSame(Invoice::STATUS_OPEN, $tiga->fresh()->status);
        $this->assertSame(12_000_000, $tiga->fresh()->amountOutstanding());

        // One entry, one bank line — which is what lets the reconciliation
        // desk tick it against the statement.
        $this->assertSame(1, PaymentEntry::query()->count());
        $this->assertSame(0, $this->ledger()->unallocated($entry));
    }

    public function test_a_partly_applied_receipt_stays_in_the_queue(): void
    {
        $faktur = $this->invoice(12_000_000);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 50_000_000, actor: $this->finance,
        );

        $this->ledger()->allocate($entry, $faktur, 12_000_000, $this->finance);

        // Somebody named a faktur, and there is still 38 juta nobody has
        // accounted for. The old queue asked only whether a faktur had been
        // named, so this row disappeared from it.
        $this->assertSame(38_000_000, $this->ledger()->unallocated($entry));
        $this->assertSame(1, PaymentEntry::query()->unmatched()->count());
    }

    public function test_a_fully_applied_receipt_leaves_the_queue(): void
    {
        $faktur = $this->invoice(12_000_000);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 12_000_000,
            actor: $this->finance, invoice: $faktur,
        );

        $this->assertSame(0, $this->ledger()->unallocated($entry));
        $this->assertSame(0, PaymentEntry::query()->unmatched()->count());
        $this->assertSame(Invoice::STATUS_PAID, $faktur->fresh()->status);
    }

    public function test_a_payment_cannot_give_out_more_than_arrived(): void
    {
        $satu = $this->invoice(30_000_000);
        $dua = $this->invoice(30_000_000);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 40_000_000, actor: $this->finance,
        );

        $this->ledger()->allocate($entry, $satu, 30_000_000, $this->finance);

        $this->expectException(DomainException::class);
        $this->ledger()->allocate($entry, $dua, 30_000_000, $this->finance);
    }

    public function test_one_customers_money_cannot_settle_anothers_bill(): void
    {
        $orangLain = Company::factory()->creditLimit(50_000_000)->create();
        $fakturnya = Invoice::factory()->totalling(5_000_000)->create([
            'company_id' => $orangLain->id,
            'issued_on' => '2026-09-02', 'due_date' => '2026-10-02',
        ]);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 10_000_000, actor: $this->finance,
        );

        $this->expectException(DomainException::class);
        $this->ledger()->allocate($entry, $fakturnya, 5_000_000, $this->finance);
    }

    // --- append-only -------------------------------------------------------

    public function test_taking_an_application_back_is_a_negative_row(): void
    {
        $faktur = $this->invoice(12_000_000);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 12_000_000, actor: $this->finance,
        );

        $alokasi = $this->ledger()->allocate($entry, $faktur, 12_000_000, $this->finance);
        $this->assertSame(Invoice::STATUS_PAID, $faktur->fresh()->status);

        $this->ledger()->unallocate($alokasi, $this->finance, 'Salah faktur.');

        // The original row is untouched; the reversal sits beside it.
        $this->assertSame(12_000_000, (int) $alokasi->fresh()->amount_rupiah);
        $this->assertSame(2, PaymentAllocation::query()->count());

        // The faktur is owed again, and the money is free to be applied.
        $this->assertSame(Invoice::STATUS_OPEN, $faktur->fresh()->status);
        $this->assertSame(12_000_000, $faktur->fresh()->amountOutstanding());
        $this->assertSame(12_000_000, $this->ledger()->unallocated($entry));
    }

    public function test_an_application_cannot_be_taken_back_twice(): void
    {
        $faktur = $this->invoice(12_000_000);
        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 12_000_000, actor: $this->finance,
        );
        $alokasi = $this->ledger()->allocate($entry, $faktur, 12_000_000, $this->finance);

        $this->ledger()->unallocate($alokasi, $this->finance, 'Salah faktur.');

        $this->expectException(DomainException::class);
        $this->ledger()->unallocate($alokasi, $this->finance, 'Sekali lagi.');
    }

    /**
     * The failure this guards against is quiet: the reversal used to reopen
     * only the single faktur named on the entry, so a transfer spread across
     * three would have left two of them settled by money that had gone back.
     */
    public function test_reversing_a_payment_takes_back_every_application(): void
    {
        $satu = $this->invoice(12_000_000, '2026-09-20');
        $dua = $this->invoice(30_000_000, '2026-09-25');

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company,
            amountRupiah: 42_000_000,
            actor: $this->finance,
            spread: [[$satu, 12_000_000], [$dua, 30_000_000]],
        );

        $this->assertSame(Invoice::STATUS_PAID, $satu->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $dua->fresh()->status);

        $this->ledger()->reverse($entry, $this->finance, 'Transfer ditarik kembali bank.');

        foreach ([$satu, $dua] as $faktur) {
            $this->assertSame(Invoice::STATUS_OPEN, $faktur->fresh()->status);
        }

        $this->assertSame(12_000_000, $satu->fresh()->amountOutstanding());
        $this->assertSame(30_000_000, $dua->fresh()->amountOutstanding());
    }

    // --- the suggestion ----------------------------------------------------

    public function test_the_suggested_spread_is_oldest_first_and_stops_at_the_money(): void
    {
        $lama = $this->invoice(12_000_000, '2026-09-10');
        $tengah = $this->invoice(30_000_000, '2026-09-20');
        $baru = $this->invoice(20_000_000, '2026-10-10');

        $rencana = $this->ledger()->suggestSpread($this->company, 25_000_000);

        $this->assertCount(2, $rencana);
        $this->assertSame($lama->id, $rencana[0]['invoice']->id);
        $this->assertSame(12_000_000, $rencana[0]['amount']);
        $this->assertSame($tengah->id, $rencana[1]['invoice']->id);
        $this->assertSame(13_000_000, $rencana[1]['amount']);

        // Nothing was written: the spread is an offer, not an act.
        $this->assertSame(0, PaymentAllocation::query()->count());
        $this->assertSame(20_000_000, $baru->fresh()->amountOutstanding());
    }

    public function test_the_suggestion_skips_what_is_already_covered(): void
    {
        $lama = $this->invoice(12_000_000, '2026-09-10');
        $baru = $this->invoice(20_000_000, '2026-10-10');

        $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 12_000_000,
            actor: $this->finance, invoice: $lama,
        );

        $rencana = $this->ledger()->suggestSpread($this->company, 5_000_000);

        $this->assertCount(1, $rencana);
        $this->assertSame($baru->id, $rencana[0]['invoice']->id);
    }

    // --- what the rest of the system reads --------------------------------

    /**
     * Commission is earned on money collected, and the date it was collected
     * comes off the payment. Read from the entries directly, an invoice
     * settled inside a multi-faktur transfer has no payment naming it — and
     * the seller silently loses the commission on every lump-sum month end.
     */
    public function test_a_faktur_settled_inside_a_lump_sum_still_knows_when_it_was_paid(): void
    {
        $faktur = $this->invoice(12_000_000);
        $lain = $this->invoice(8_000_000);

        $this->ledger()->recordManualPayment(
            company: $this->company,
            amountRupiah: 20_000_000,
            actor: $this->finance,
            paidAt: Carbon::parse('2026-09-15 10:00:00'),
            spread: [[$faktur, 12_000_000], [$lain, 8_000_000]],
        );

        $settledAt = $faktur->allocations()
            ->join('payment_entries', 'payment_entries.id', '=', 'payment_allocations.payment_entry_id')
            ->max('payment_entries.paid_at');

        $this->assertNotNull($settledAt);
        $this->assertStringStartsWith('2026-09-15', (string) $settledAt);

        // The entry names no single faktur, which is exactly why the money
        // has to be counted through the allocations.
        $this->assertNull(PaymentEntry::query()->sole()->invoice_id);
    }

    public function test_settlement_still_advances_the_order_when_the_money_is_shared(): void
    {
        $faktur = $this->invoice(12_000_000);
        $lain = $this->invoice(8_000_000);

        $entry = $this->ledger()->recordManualPayment(
            company: $this->company,
            amountRupiah: 20_000_000,
            actor: $this->finance,
            spread: [[$faktur, 12_000_000], [$lain, 8_000_000]],
        );

        $this->assertSame(0, $this->ledger()->unallocated($entry));

        foreach ([$faktur, $lain] as $i) {
            $this->assertSame(Invoice::STATUS_PAID, $i->fresh()->status);
            $this->assertSame(0, $i->fresh()->amountOutstanding());
        }
    }

    // --- permissions -------------------------------------------------------

    public function test_only_a_seat_that_may_confirm_money_may_apply_it(): void
    {
        $faktur = $this->invoice(12_000_000);
        $entry = $this->ledger()->recordManualPayment(
            company: $this->company, amountRupiah: 12_000_000, actor: $this->finance,
        );

        $this->expectException(\LogicException::class);
        $this->ledger()->allocate($entry, $faktur, 12_000_000, User::factory()->sales()->create());
    }
}
