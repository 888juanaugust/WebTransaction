<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Giro\GiroRegister;
use App\Domain\Giro\GiroStatus;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PaymentEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A bilyet giro is written for what a customer owes on their account, not for
 * one faktur — so it is routinely worth more than the document it is handed
 * over against, and by the time it clears that document may have been settled
 * another way entirely.
 *
 * The ledgers refuse to over-apply, which is right. Passing a whole cheque at
 * one invoice therefore **threw on the day it cleared**, after the bank had
 * already moved the money: the one moment the books must not refuse to record
 * what happened. That failure was introduced by the allocation work and is
 * what this file exists to keep shut.
 *
 * The rule: the named document takes what it still owes, the remainder lands
 * unallocated in the queue, and nothing is ever applied to a document nobody
 * named — at clearing there is no one to ask.
 */
class GiroClearingAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $company;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->finance = User::factory()->finance()->create();
        $this->company = Company::factory()->creditLimit(500_000_000)->create();
        $this->supplier = Supplier::factory()->create();
    }

    private function invoice(int $amount, string $due = '2026-09-20'): Invoice
    {
        $invoice = Invoice::factory()->totalling($amount)->create([
            'company_id' => $this->company->id,
            'issued_on' => '2026-09-02',
            'due_date' => $due,
            'status' => Invoice::STATUS_OPEN,
        ]);

        app(DocumentPoster::class)->invoiceIssued($invoice, $this->finance);

        return $invoice;
    }

    private function bill(int $amount): SupplierBill
    {
        return SupplierBill::factory()->totalling($amount)->create([
            'supplier_id' => $this->supplier->id,
            'status' => SupplierBill::STATUS_OPEN,
        ]);
    }

    private function receiveAndClear(int $nilai, ?Invoice $invoice): void
    {
        $register = app(GiroRegister::class);

        $giro = $register->receive(
            company: $this->company,
            invoice: $invoice,
            nilaiRupiah: $nilai,
            bankPenerbit: 'BCA',
            nomorWarkat: 'AA'.fake()->unique()->numerify('######'),
            jatuhTempo: Carbon::parse('2026-09-30'),
            actor: $this->finance,
        );

        $register->markDeposited($giro, $this->finance, Carbon::parse('2026-09-30'));
        $register->clear($giro->fresh(), $this->finance, Carbon::parse('2026-10-01'));
    }

    // --- money in ----------------------------------------------------------

    /** The scenario that used to throw. */
    public function test_a_cheque_worth_more_than_its_faktur_still_clears(): void
    {
        $satu = $this->invoice(12_000_000, '2026-09-20');
        $dua = $this->invoice(30_000_000, '2026-09-25');

        $this->receiveAndClear(42_000_000, $satu);

        // The named faktur is settled, to the rupiah.
        $this->assertSame(Invoice::STATUS_PAID, $satu->fresh()->status);
        $this->assertSame(0, $satu->fresh()->amountOutstanding());

        // Nothing was applied to the faktur nobody named.
        $this->assertSame(30_000_000, $dua->fresh()->amountOutstanding());
        $this->assertSame(Invoice::STATUS_OPEN, $dua->fresh()->status);

        // The rest is visible, in the queue built for it.
        $entry = PaymentEntry::query()->sole();
        $this->assertSame(42_000_000, (int) $entry->amount_rupiah);
        $this->assertSame(30_000_000, app(PaymentLedger::class)->unallocated($entry));
        $this->assertSame(1, PaymentEntry::query()->unmatched()->count());
    }

    public function test_a_cheque_smaller_than_its_faktur_pays_what_it_is_worth(): void
    {
        $faktur = $this->invoice(30_000_000);

        $this->receiveAndClear(12_000_000, $faktur);

        $this->assertSame(Invoice::STATUS_OPEN, $faktur->fresh()->status);
        $this->assertSame(18_000_000, $faktur->fresh()->amountOutstanding());
        $this->assertSame(0, app(PaymentLedger::class)->unallocated(PaymentEntry::query()->sole()));
    }

    /**
     * The customer paid by transfer while the cheque sat in the drawer, and
     * the cheque cleared anyway. Nothing is owed on that faktur any more, so
     * the whole cheque waits to be applied rather than pushing it negative.
     */
    public function test_a_cheque_whose_faktur_was_settled_meanwhile_clears_unallocated(): void
    {
        $faktur = $this->invoice(12_000_000);

        app(PaymentLedger::class)->recordManualPayment(
            company: $this->company, amountRupiah: 12_000_000,
            actor: $this->finance, invoice: $faktur,
        );
        $this->assertSame(Invoice::STATUS_PAID, $faktur->fresh()->status);

        $this->receiveAndClear(12_000_000, $faktur);

        // Still settled, still exactly zero — not minus twelve million.
        $this->assertSame(0, $faktur->fresh()->amountOutstanding());

        $giroEntry = PaymentEntry::query()->orderByDesc('id')->first();
        $this->assertSame(12_000_000, app(PaymentLedger::class)->unallocated($giroEntry));
    }

    public function test_a_cheque_against_no_faktur_clears_into_the_queue(): void
    {
        $this->invoice(30_000_000);

        $this->receiveAndClear(20_000_000, null);

        $entry = PaymentEntry::query()->sole();
        $this->assertSame(20_000_000, app(PaymentLedger::class)->unallocated($entry));
        $this->assertSame(1, PaymentEntry::query()->unmatched()->count());
    }

    // --- money out ---------------------------------------------------------

    public function test_our_own_cheque_worth_more_than_its_tagihan_still_clears(): void
    {
        $satu = $this->bill(12_000_000);
        $dua = $this->bill(30_000_000);

        $register = app(GiroRegister::class);

        $giro = $register->issue(
            supplier: $this->supplier,
            bill: $satu,
            nilaiRupiah: 42_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'KK112233',
            jatuhTempo: Carbon::parse('2026-09-30'),
            actor: $this->finance,
        );

        $register->clear($giro->fresh(), $this->finance, Carbon::parse('2026-10-01'));

        $this->assertSame(SupplierBill::STATUS_PAID, $satu->fresh()->status);
        $this->assertSame(30_000_000, $dua->fresh()->amountOutstanding());

        $entry = SupplierPaymentEntry::query()->sole();
        $this->assertSame(30_000_000, app(SupplierLedger::class)->unallocated($entry));
    }

    // --- the giro itself is untouched by any of this -----------------------

    /**
     * A bounced cheque was never a payment, so there is nothing to unwind —
     * the debt simply stands. Worth pinning here, because the clearing path
     * now writes allocations and a bounce must still write none.
     */
    public function test_a_bounced_cheque_leaves_the_faktur_exactly_as_it_was(): void
    {
        $faktur = $this->invoice(30_000_000);

        $register = app(GiroRegister::class);

        $giro = $register->receive(
            company: $this->company,
            invoice: $faktur,
            nilaiRupiah: 30_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'AA999888',
            jatuhTempo: Carbon::parse('2026-09-30'),
            actor: $this->finance,
        );

        $register->markDeposited($giro, $this->finance, Carbon::parse('2026-09-30'));
        $register->bounce($giro->fresh(), $this->finance, 'Saldo tidak cukup.');

        $this->assertSame(GiroStatus::Ditolak, $giro->fresh()->status);
        $this->assertSame(30_000_000, $faktur->fresh()->amountOutstanding());
        $this->assertSame(0, PaymentEntry::query()->count());
    }
}
