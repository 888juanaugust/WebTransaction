<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Reporting\PayablesAgeing;
use App\Domain\Reporting\ReceivablesAgeing;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One transfer out, several tagihan — the payables mirror.
 *
 * The defect was the same shape as the receivable side and quieter: recording
 * a Rp 42.000.000 transfer against a Rp 12.000.000 bill marked it paid and
 * left it at **minus thirty million**, while the Rp 30.000.000 bill the same
 * transfer covered stayed fully open and went on ageing. The supplier's
 * *total* netted to the right figure, which is exactly why nobody would have
 * noticed until they queried a statement.
 *
 * Two of these tests are about the ageing reports rather than the ledger, and
 * they exist because that gap was real: the sell side shipped without them and
 * `ReceivablesAgeing` was left summing the entries, so a faktur settled inside
 * a spread aged at its full amount while `amountOutstanding()` called it paid.
 */
class SupplierPaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->finance = User::factory()->finance()->create();
        $this->supplier = Supplier::factory()->create();
    }

    private function bill(int $amount, string $due = '2026-10-02'): SupplierBill
    {
        return SupplierBill::factory()->totalling($amount)->create([
            'supplier_id' => $this->supplier->id,
            'due_date' => $due,
            'status' => SupplierBill::STATUS_OPEN,
        ]);
    }

    private function ledger(): SupplierLedger
    {
        return app(SupplierLedger::class);
    }

    // --- the bug this file exists for -------------------------------------

    public function test_a_payment_cannot_be_applied_beyond_what_a_bill_owes(): void
    {
        $kecil = $this->bill(12_000_000);
        $besar = $this->bill(30_000_000);

        try {
            $this->ledger()->recordPayment(
                supplier: $this->supplier,
                amountRupiah: 42_000_000,
                actor: $this->finance,
                bill: $kecil,
            );
            $this->fail('Over-paying one bill must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Rp 12.000.000', $e->getMessage());
            $this->assertStringContainsString('Rp 42.000.000', $e->getMessage());
        }

        $this->assertSame(12_000_000, $kecil->fresh()->amountOutstanding());
        $this->assertSame(30_000_000, $besar->fresh()->amountOutstanding());
        $this->assertSame(SupplierBill::STATUS_OPEN, $kecil->fresh()->status);

        // Nothing was written at all — the refusal happens inside the
        // transaction that would have created the entry.
        $this->assertSame(0, SupplierPaymentEntry::query()->count());
    }

    public function test_one_transfer_discharges_several_bills(): void
    {
        $satu = $this->bill(12_000_000, '2026-09-20');
        $dua = $this->bill(30_000_000, '2026-09-25');
        $tiga = $this->bill(20_000_000, '2026-10-05');

        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier,
            amountRupiah: 50_000_000,
            actor: $this->finance,
            spread: [[$satu, 12_000_000], [$dua, 30_000_000], [$tiga, 8_000_000]],
        );

        $this->assertSame(SupplierBill::STATUS_PAID, $satu->fresh()->status);
        $this->assertSame(SupplierBill::STATUS_PAID, $dua->fresh()->status);
        $this->assertSame(SupplierBill::STATUS_OPEN, $tiga->fresh()->status);
        $this->assertSame(12_000_000, $tiga->fresh()->amountOutstanding());

        // One entry, one bank line — what the reconciliation desk ticks.
        $this->assertSame(1, SupplierPaymentEntry::query()->count());
        $this->assertSame(0, $this->ledger()->unallocated($entry));
    }

    public function test_a_partly_applied_payment_stays_in_the_queue(): void
    {
        $tagihan = $this->bill(12_000_000);

        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier, amountRupiah: 50_000_000, actor: $this->finance,
        );

        $this->ledger()->allocate($entry, $tagihan, 12_000_000, $this->finance);

        $this->assertSame(38_000_000, $this->ledger()->unallocated($entry));
        $this->assertSame(1, SupplierPaymentEntry::query()->unmatched()->count());
    }

    public function test_a_payment_cannot_give_out_more_than_left_the_account(): void
    {
        $satu = $this->bill(30_000_000);
        $dua = $this->bill(30_000_000);

        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier, amountRupiah: 40_000_000, actor: $this->finance,
        );

        $this->ledger()->allocate($entry, $satu, 30_000_000, $this->finance);

        $this->expectException(DomainException::class);
        $this->ledger()->allocate($entry, $dua, 30_000_000, $this->finance);
    }

    public function test_one_suppliers_money_cannot_discharge_anothers_bill(): void
    {
        $lain = Supplier::factory()->create();
        $tagihannya = SupplierBill::factory()->totalling(5_000_000)->create([
            'supplier_id' => $lain->id, 'status' => SupplierBill::STATUS_OPEN,
        ]);

        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier, amountRupiah: 10_000_000, actor: $this->finance,
        );

        $this->expectException(DomainException::class);
        $this->ledger()->allocate($entry, $tagihannya, 5_000_000, $this->finance);
    }

    // --- append-only -------------------------------------------------------

    public function test_taking_an_application_back_is_a_negative_row(): void
    {
        $tagihan = $this->bill(12_000_000);

        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier, amountRupiah: 12_000_000, actor: $this->finance,
        );

        $alokasi = $this->ledger()->allocate($entry, $tagihan, 12_000_000, $this->finance);
        $this->assertSame(SupplierBill::STATUS_PAID, $tagihan->fresh()->status);

        $this->ledger()->unallocate($alokasi, $this->finance, 'Salah tagihan.');

        $this->assertSame(12_000_000, (int) $alokasi->fresh()->amount_rupiah);
        $this->assertSame(2, SupplierPaymentAllocation::query()->count());

        $this->assertSame(SupplierBill::STATUS_OPEN, $tagihan->fresh()->status);
        $this->assertSame(12_000_000, $tagihan->fresh()->amountOutstanding());
        $this->assertSame(12_000_000, $this->ledger()->unallocated($entry));
    }

    public function test_reversing_a_payment_takes_back_every_application(): void
    {
        $satu = $this->bill(12_000_000, '2026-09-20');
        $dua = $this->bill(30_000_000, '2026-09-25');

        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier,
            amountRupiah: 42_000_000,
            actor: $this->finance,
            spread: [[$satu, 12_000_000], [$dua, 30_000_000]],
        );

        $this->assertSame(SupplierBill::STATUS_PAID, $satu->fresh()->status);
        $this->assertSame(SupplierBill::STATUS_PAID, $dua->fresh()->status);

        $this->ledger()->reverse($entry, $this->finance, 'Transfer gagal.');

        foreach ([$satu, $dua] as $tagihan) {
            $this->assertSame(SupplierBill::STATUS_OPEN, $tagihan->fresh()->status);
        }

        $this->assertSame(12_000_000, $satu->fresh()->amountOutstanding());
        $this->assertSame(30_000_000, $dua->fresh()->amountOutstanding());
    }

    // --- the suggestion ----------------------------------------------------

    public function test_the_suggested_spread_is_oldest_first_and_stops_at_the_money(): void
    {
        $lama = $this->bill(12_000_000, '2026-09-10');
        $tengah = $this->bill(30_000_000, '2026-09-20');
        $baru = $this->bill(20_000_000, '2026-10-10');

        $rencana = $this->ledger()->suggestSpread($this->supplier, 25_000_000);

        $this->assertCount(2, $rencana);
        $this->assertSame($lama->id, $rencana[0]['bill']->id);
        $this->assertSame(12_000_000, $rencana[0]['amount']);
        $this->assertSame($tengah->id, $rencana[1]['bill']->id);
        $this->assertSame(13_000_000, $rencana[1]['amount']);

        // An offer, not an act.
        $this->assertSame(0, SupplierPaymentAllocation::query()->count());
        $this->assertSame(20_000_000, $baru->fresh()->amountOutstanding());
    }

    // --- what the ageing reports say --------------------------------------

    /**
     * The gap the sell side shipped with.
     *
     * Umur hutang must agree with the bill. Summing the entries by their
     * `supplier_bill_id` misses a bill discharged inside a lump payment, and
     * the report would then age a debt that has been settled.
     */
    public function test_umur_hutang_agrees_with_a_bill_paid_inside_a_lump_sum(): void
    {
        $satu = $this->bill(12_000_000, '2026-09-20');
        $dua = $this->bill(30_000_000, '2026-09-25');

        $this->ledger()->recordPayment(
            supplier: $this->supplier,
            amountRupiah: 20_000_000,
            actor: $this->finance,
            spread: [[$satu, 12_000_000], [$dua, 8_000_000]],
        );

        $this->assertSame(0, $satu->fresh()->amountOutstanding());
        $this->assertSame(22_000_000, $dua->fresh()->amountOutstanding());

        // The report's own total is what the books have to tie to.
        $laporan = app(PayablesAgeing::class)->build();
        $this->assertSame(22_000_000, (int) $laporan->totals['total']);
    }

    /** The same rule on the receivable side, which had no test at all. */
    public function test_umur_piutang_agrees_with_a_faktur_paid_inside_a_lump_sum(): void
    {
        $company = Company::factory()->creditLimit(500_000_000)->create();

        $satu = Invoice::factory()->totalling(12_000_000)->create([
            'company_id' => $company->id, 'issued_on' => '2026-09-02',
            'due_date' => '2026-09-20', 'status' => Invoice::STATUS_OPEN,
        ]);
        $dua = Invoice::factory()->totalling(30_000_000)->create([
            'company_id' => $company->id, 'issued_on' => '2026-09-02',
            'due_date' => '2026-09-25', 'status' => Invoice::STATUS_OPEN,
        ]);

        app(PaymentLedger::class)->recordManualPayment(
            company: $company,
            amountRupiah: 20_000_000,
            actor: $this->finance,
            spread: [[$satu, 12_000_000], [$dua, 8_000_000]],
        );

        $this->assertSame(0, $satu->fresh()->amountOutstanding());
        $this->assertSame(22_000_000, $dua->fresh()->amountOutstanding());

        $laporan = app(ReceivablesAgeing::class)->build();
        $this->assertSame(22_000_000, (int) $laporan->totals['total']);
    }

    // --- permissions -------------------------------------------------------

    public function test_only_a_seat_that_may_confirm_money_may_apply_it(): void
    {
        $tagihan = $this->bill(12_000_000);
        $entry = $this->ledger()->recordPayment(
            supplier: $this->supplier, amountRupiah: 12_000_000, actor: $this->finance,
        );

        $this->expectException(DomainException::class);
        $this->ledger()->allocate($entry, $tagihan, 12_000_000, User::factory()->sales()->create());
    }
}
