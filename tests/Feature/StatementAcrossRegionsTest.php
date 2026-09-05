<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Regions\RegionContext;
use App\Domain\Reporting\CustomerStatement;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportTable;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Region;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The rekening koran, when the customer's goods came from two regions.
 *
 * The multi-warehouse split books each piece of an order in the region that
 * shipped it. `HasRegion` already steps aside for the `customer` guard, so the
 * buyer's own portal was never short — but **the statement is not a portal
 * screen**. It is built by staff, on the `web` guard, from the Laporan page
 * and the printable document, and there the scope applies in full. Staff in
 * Surabaya printed a Surabaya-shaped account and posted it to the customer.
 *
 * Measured on one customer:
 *
 *     what they actually owe    Rp 11.100.000
 *     the statement closed at   Rp  6.660.000
 *
 * Forty per cent understated, in the customer's favour, over our name, on the
 * one document written to settle an argument about exactly this figure. It
 * would have settled it — wrongly, and in writing.
 *
 * The general rule, which is what these tests defend rather than the instance:
 * **a read filtered to one company crosses regions; a region-wide total does
 * not.** That is CLAUDE.md's rule, and the failure mode when it is missed is
 * never an error — it is a plausible smaller number.
 *
 * So the closing balance is asserted as an *identity* against what the credit
 * check spends, not against a literal. Two rules that happen to agree today is
 * not one rule, and the next reader to stop one relation short should fail
 * here rather than post the difference to a customer.
 */
class StatementAcrossRegionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $pelanggan;

    private Order $home;

    private Order $asing;

    private Region $sby;

    private Region $jkt;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->sby = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT']);

        $gudang = Warehouse::factory()->create(['kode' => 'GD-SBY']);
        $gudangJkt = app(RegionContext::class)->within(
            $this->jkt, fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        $owner = User::factory()->owner()->create();
        $sales = User::factory()->sales()->create(['region_id' => $this->sby->id]);
        $marketing = User::factory()->marketing()->create(['region_id' => null]);
        $this->finance = User::factory()->finance()->create();

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'Bengkel Dua Wilayah',
            'status' => Company::STATUS_ACTIVE,
        ]);
        app(TeamAssigner::class)->assignSales($this->pelanggan, $sales, $owner);
        app(TeamAssigner::class)->assignMarketing($this->pelanggan, $marketing, $owner);

        Product::factory()->create(['kode' => 'CR-1', 'qty_per_ctn' => 10]);
        $version = PriceListVersion::query()->where('status', 'published')->first()
            ?? PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => 'CR-1', 'harga' => 100_000,
        ]);

        // Sixty in Surabaya, a hundred in Jakarta: an order for a hundred has
        // to come out of both, which is what makes the split happen.
        foreach ([[$gudang, 60], [$gudangJkt, 100]] as [$g, $qty]) {
            app(RegionContext::class)->within((int) $g->region_id, fn () => StockLevel::query()->create([
                'sku' => 'CR-1', 'warehouse_id' => $g->id, 'qty_on_hand' => $qty, 'qty_reserved' => 0,
            ]));
        }

        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id, 'warehouse_id' => $gudang->id,
        ]);
        OrderLine::factory()->qty(100)->create([
            'order_id' => $order->id, 'sku' => 'CR-1', 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order, $sales);
        $machine->confirm($order->refresh(), $marketing);

        $this->home = $order->refresh();
        $this->asing = $this->home->splitChildren()->sole();

        // Both pieces billed, each in its own region's books.
        $machine->awaitPayment($this->home->refresh(), $this->finance);
        app(RegionContext::class)->within(
            $this->jkt, fn () => $machine->awaitPayment($this->asing->refresh(), $this->finance),
        );

        $this->home->refresh();
        $this->asing->refresh();

        // Whoever prints this is staff working in the customer's home region:
        // no buyer in session, region pinned, scope live.
        app(RegionContext::class)->pinTo($this->sby);
    }

    /** Everything the customer owes, read with the scope deliberately off. */
    private function seluruhnya(): int
    {
        return (int) Invoice::query()->withoutGlobalScope('region')
            ->where('company_id', $this->pelanggan->id)
            ->sum('total_rupiah');
    }

    private function fakturAsing(): Invoice
    {
        return Invoice::query()->withoutGlobalScope('region')
            ->where('order_id', $this->asing->id)
            ->sole();
    }

    private function statement(?string $from = null, ?string $to = null): ReportTable
    {
        return app(CustomerStatement::class)->build(
            $this->pelanggan,
            Period::between(
                $from ?? today()->subMonth()->toDateString(),
                $to ?? today()->toDateString(),
            ),
        );
    }

    // --- the fixture really is the situation ---------------------------------

    public function test_the_two_pieces_booked_in_different_regions(): void
    {
        // Without this the rest of the file could pass on a fixture that never
        // reproduced anything.
        $this->assertNotSame((int) $this->home->region_id, (int) $this->asing->region_id);
        $this->assertSame((int) $this->sby->id, (int) $this->pelanggan->region_id);
        $this->assertSame(11_100_000, $this->seluruhnya());

        // And a plain staff read genuinely does come up short — this is the
        // 6.660.000 the statement was closing on.
        $this->assertSame(
            6_660_000,
            (int) Invoice::query()->where('company_id', $this->pelanggan->id)->sum('total_rupiah'),
        );
    }

    // --- the statement -------------------------------------------------------

    public function test_it_closes_on_what_the_credit_check_says_they_owe(): void
    {
        $table = $this->statement();

        $this->assertSame($this->seluruhnya(), $table->totals['saldo']);
        $this->assertSame(
            app(OutstandingReceivables::class)->forCompany($this->pelanggan),
            $table->totals['saldo'],
        );
    }

    public function test_the_other_regions_faktur_is_a_row_on_the_page(): void
    {
        // Not merely counted into the total: the customer has to be able to
        // see which document the money is for, or the figure is unarguable in
        // the wrong direction.
        $table = $this->statement();

        $this->assertCount(3, $table->rows); // saldo awal + two fakturs
        $this->assertContains($this->fakturAsing()->nomor, array_column($table->rows, 'dokumen'));
    }

    public function test_the_opening_balance_carries_it_too(): void
    {
        /*
         * The same blind spot one method along, and harder to catch: an
         * opening balance short by a faktur leaves nothing on the page that
         * looks missing. It is also the figure a customer's bookkeeper checks
         * against last month's statement.
         */
        $table = $this->statement(
            today()->addDay()->toDateString(),
            today()->addMonth()->toDateString(),
        );

        $this->assertSame($this->seluruhnya(), $table->rows[0]['saldo']);
    }

    public function test_a_payment_banked_in_the_other_region_is_credited(): void
    {
        // The mirror. Fixing the invoices alone would have swapped an
        // understated balance for an overstated one — worse, because the
        // customer knows they paid.
        app(RegionContext::class)->within($this->jkt, fn () => app(PaymentLedger::class)
            ->recordManualPayment(
                company: $this->pelanggan,
                amountRupiah: 4_000_000,
                actor: $this->finance,
                invoice: $this->fakturAsing(),
                paidAt: Carbon::parse(today()->toDateString()),
            ));

        $table = $this->statement();

        $this->assertSame($this->seluruhnya() - 4_000_000, $table->totals['saldo']);
        $this->assertSame(
            app(OutstandingReceivables::class)->forCompany($this->pelanggan),
            $table->totals['saldo'],
        );
    }

    public function test_a_credit_note_raised_in_the_other_region_is_credited(): void
    {
        app(RegionContext::class)->within($this->jkt, function () {
            $note = CreditNote::factory()->create([
                'company_id' => $this->pelanggan->id,
                'tanggal' => today()->toDateString(),
            ]);

            $note->forceFill([
                'subtotal_rupiah' => 1_500_000,
                'ppn_rupiah' => 0,
                'total_rupiah' => 1_500_000,
                'status' => CreditNote::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });

        $table = $this->statement();

        $this->assertSame($this->seluruhnya() - 1_500_000, $table->totals['saldo']);
        $this->assertSame(
            app(OutstandingReceivables::class)->forCompany($this->pelanggan),
            $table->totals['saldo'],
        );
    }

    /**
     * A transfer settling fakturs in two regions at once — ordinary for a
     * customer on 30-day terms, and the case where the notes had to change
     * with the allocation ledger as well as the region scope.
     */
    public function test_one_transfer_spread_across_both_regions_fakturs(): void
    {
        $home = Invoice::query()->withoutGlobalScope('region')
            ->where('order_id', $this->home->id)->sole();

        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: 5_000_000,
            actor: $this->finance,
            paidAt: Carbon::parse(today()->toDateString()),
            spread: [[$home, 3_000_000], [$this->fakturAsing(), 2_000_000]],
        );

        $table = $this->statement();

        $this->assertSame($this->seluruhnya() - 5_000_000, $table->totals['saldo']);

        $baris = collect($table->rows)->firstWhere('pembayaran', 5_000_000);

        $this->assertNotNull($baris, 'The transfer must appear as one payment row.');

        // Both fakturs named. Read off `invoice_id` this column was blank,
        // because a spread payment names no single faktur.
        $this->assertStringContainsString($home->nomor, $baris['dokumen']);
        $this->assertStringContainsString($this->fakturAsing()->nomor, $baris['dokumen']);

        $this->assertStringContainsString('2 faktur', $baris['keterangan']);

        // And nothing is left over, so no "unmatched" note is added.
        $this->assertEmpty(array_filter(
            $table->catatan,
            fn (string $c) => str_contains($c, 'belum dicocokkan'),
        ));
    }
}
