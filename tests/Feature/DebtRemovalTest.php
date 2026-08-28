<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Credit\DebtAging;
use App\Domain\Credit\DebtRemovalStatus;
use App\Domain\Credit\DebtRemover;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentEntry;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penghapusan piutang — two keys, never the same hand.
 *
 * The claims pinned here: only the marketing in charge may say "the customer
 * paid me"; only finance may say "the money is real"; the two are never one
 * person; and the approval is an ordinary ledger payment, so everything a
 * payment settles — the invoice, the order, the four-month freeze — settles
 * exactly the same way when the cash came through a house visit.
 */
class DebtRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $sales;

    private User $marketing;

    private User $finance;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $region = $this->currentRegion();
        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $region->id]);
        $this->marketing = User::factory()->marketing()->create(['region_id' => $region->id]);
        $this->finance = User::factory()->finance()->create(['region_id' => $region->id]);

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create();

        $assigner = app(TeamAssigner::class);
        $assigner->assignSales($this->pelanggan, $this->sales, $this->owner);
        $assigner->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    private function remover(): DebtRemover
    {
        return app(DebtRemover::class);
    }

    private function openInvoice(int $total = 5_000_000): Invoice
    {
        return Invoice::factory()->totalling($total)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subMonth(),
            'due_date' => today()->subWeek(),
        ]);
    }

    // ------------------------------------------------------------ initiating

    public function test_the_salesperson_cannot_claim_a_debt_was_paid(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/marketing penanggung jawab/');

        $this->remover()->initiate($this->openInvoice(), $this->sales, 5_000_000, 'Dibayar tunai di toko.');
    }

    public function test_a_marketing_not_in_charge_cannot_claim_it_either(): void
    {
        $lain = User::factory()->marketing()->create(['region_id' => $this->currentRegion()->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/bukan tanggung jawab Anda/');

        $this->remover()->initiate($this->openInvoice(), $lain, 5_000_000, 'Dibayar tunai di toko.');
    }

    public function test_the_marketing_in_charge_files_the_claim_and_nothing_moves_yet(): void
    {
        $invoice = $this->openInvoice();

        $removal = $this->remover()->initiate($invoice, $this->marketing, 5_000_000, 'Tunai diterima saat kunjungan 27/08.');

        $this->assertSame(DebtRemovalStatus::Diajukan, $removal->status);

        // The claim is paperwork, not money: no payment entry, invoice open,
        // outstanding untouched.
        $this->assertSame(0, PaymentEntry::query()->count());
        $this->assertSame(Invoice::STATUS_OPEN, $invoice->refresh()->status);
        $this->assertSame(5_000_000, $invoice->amountOutstanding());

        $this->assertSame(1, AuditLog::query()->where('action', 'debt_removal_initiated')->count());
    }

    public function test_a_claim_cannot_exceed_the_outstanding_balance(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sisa tagihan/');

        $this->remover()->initiate($this->openInvoice(5_000_000), $this->marketing, 5_000_001, 'Tunai.');
    }

    public function test_one_live_claim_per_invoice(): void
    {
        $invoice = $this->openInvoice();
        $this->remover()->initiate($invoice, $this->marketing, 2_000_000, 'Tunai tahap pertama.');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah punya pengajuan/');

        $this->remover()->initiate($invoice, $this->marketing, 3_000_000, 'Tunai tahap kedua.');
    }

    // -------------------------------------------------------------- deciding

    public function test_marketing_cannot_verify_what_marketing_claimed(): void
    {
        $invoice = $this->openInvoice();
        $removal = $this->remover()->initiate($invoice, $this->marketing, 5_000_000, 'Tunai.');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/keputusan finance/');

        $this->remover()->approve($removal, $this->marketing);
    }

    public function test_the_initiator_cannot_verify_their_own_claim(): void
    {
        /*
         * The Owner holds both keys — but never for the same claim. Without
         * this line, "two people must agree" quietly becomes "the Owner
         * agrees with themselves".
         */
        $invoice = $this->openInvoice();
        $removal = $this->remover()->initiate($invoice, $this->owner, 5_000_000, 'Tunai ke saya langsung.');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/dua kunci/');

        $this->remover()->approve($removal, $this->owner);
    }

    public function test_finance_approval_is_an_ordinary_ledger_payment(): void
    {
        $invoice = $this->openInvoice();
        $removal = $this->remover()->initiate($invoice, $this->marketing, 5_000_000, 'Tunai saat kunjungan.');

        $removal = $this->remover()->approve($removal, $this->finance, 'Uang diterima kasir, cocok.');

        $this->assertSame(DebtRemovalStatus::Disetujui, $removal->status);
        $this->assertNotNull($removal->payment_entry_id);

        // The money is a payment entry like any other — appended, never a
        // mutation of the invoice — and it settled the invoice.
        $entry = $removal->paymentEntry;
        $this->assertSame(5_000_000, $entry->amount_rupiah);
        $this->assertSame($this->finance->id, $entry->actor_id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);

        $this->assertSame(1, AuditLog::query()->where('action', 'debt_removal_approved')->count());
    }

    public function test_a_partial_claim_leaves_the_rest_owing(): void
    {
        $invoice = $this->openInvoice(5_000_000);
        $removal = $this->remover()->initiate($invoice, $this->marketing, 2_000_000, 'Tunai sebagian.');

        $this->remover()->approve($removal, $this->finance);

        $this->assertSame(Invoice::STATUS_OPEN, $invoice->refresh()->status);
        $this->assertSame(3_000_000, $invoice->amountOutstanding());
    }

    public function test_a_stale_claim_is_refused_when_the_balance_has_shrunk(): void
    {
        $invoice = $this->openInvoice(5_000_000);
        $removal = $this->remover()->initiate($invoice, $this->marketing, 5_000_000, 'Tunai penuh.');

        // A transfer lands between the claim and the verification.
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: 4_000_000,
            actor: $this->finance,
            invoice: $invoice,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/mengajukan ulang/');

        $this->remover()->approve($removal->refresh(), $this->finance);
    }

    public function test_rejection_needs_a_reason_and_frees_the_invoice(): void
    {
        $invoice = $this->openInvoice();
        $removal = $this->remover()->initiate($invoice, $this->marketing, 5_000_000, 'Tunai.');

        try {
            $this->remover()->reject($removal, $this->finance, '  ');
            $this->fail('A reasonless rejection should be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kenapa ditolak', $e->getMessage());
        }

        $this->remover()->reject($removal, $this->finance, 'Kasir tidak menerima uang ini.');
        $this->assertSame(DebtRemovalStatus::Ditolak, $removal->refresh()->status);

        // Rejected is terminal for the claim but not for the debt: a
        // corrected claim can be filed.
        $baru = $this->remover()->initiate($invoice, $this->marketing, 5_000_000, 'Ternyata transfer, bukti terlampir.');
        $this->assertSame(DebtRemovalStatus::Diajukan, $baru->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah Ditolak/');
        $this->remover()->approve($removal->refresh(), $this->finance);
    }

    // ------------------------------------------------- what settlement drags

    public function test_an_approved_removal_completes_the_shipped_order_and_lifts_the_freeze(): void
    {
        /*
         * The whole point of routing approval through the payment ledger:
         * a debt paid in cash at the customer's counter settles everything
         * a bank transfer settles. The shipped order completes (null actor —
         * finished means paid) and the four-month freeze lifts with nothing
         * to reset.
         */
        $gudang = Warehouse::factory()->create();
        $product = Product::factory()->create(['qty_per_ctn' => 10]);
        StockLevel::query()->create([
            'sku' => $product->kode, 'warehouse_id' => $gudang->id,
            'qty_on_hand' => 100, 'qty_reserved' => 0,
        ]);
        $version = PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => $product->kode, 'harga' => 100_000,
        ]);

        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $gudang->id,
        ]);
        OrderLine::factory()->qty(5)->create(['order_id' => $order->id, 'sku' => $product->kode]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order, $this->sales);
        $machine->confirm($order->refresh(), $this->marketing);
        $machine->awaitPayment($order->refresh());
        $machine->ship($order->refresh(), User::factory()->warehouse()->create());

        $invoice = $order->refresh()->invoice;
        $invoice->forceFill(['issued_on' => today()->subMonths(5)])->save();
        $this->assertTrue(app(DebtAging::class)->isFrozen($this->pelanggan));

        $removal = $this->remover()->initiate(
            $invoice->refresh(), $this->marketing, $invoice->amountOutstanding(), 'Tunai penuh saat penagihan.',
        );
        $this->remover()->approve($removal, $this->finance);

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertFalse(app(DebtAging::class)->isFrozen($this->pelanggan));
    }
}
