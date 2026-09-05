<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\ControlAccountCheck;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\PeriodCloser;
use App\Domain\Banking\BankAccounts;
use App\Domain\Banking\BankReconciler;
use App\Domain\Integrity\LedgerIntegrity;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Regions\RegionContext;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A region's books are checked against a region's subledgers.
 *
 * `TrialBalance` had this right — it filters `journal_entries` explicitly,
 * with a comment saying why: lines carry no region, their entry does, and
 * starting a query from the line model puts it outside the `HasRegion` scope.
 * `Ledger::balanceOf` made the identical join and omitted the filter, so
 * **every general-ledger balance in the system was the whole company's** while
 * the subledgers it gets compared against were one region's.
 *
 * In a one-region business those are the same number and nothing ever says
 * anything. With two regions trading identically and settling in full, where
 * every figure below should be zero:
 *
 *     Persediaan            GL 60.000.000   in region 30.000.000   sub 30.000.000
 *     Utang Belum Ditagih   GL 60.000.000   in region 30.000.000   sub 60.000.000
 *
 * Two failures wearing the same clothes. Persediaan compared a company-wide
 * ledger against one region's subledger and reported drift that was not there.
 * Utang Belum Ditagih was company-wide on *both* sides — its subledger reaches
 * `goods_receipts` and `supplier_bills` through joins too — so it agreed for
 * the wrong reason and would have started failing the moment the ledger side
 * alone was fixed. Which is why both sides move together here.
 *
 * What it would have cost, and this is the part that matters: two of these
 * findings **block the month-end close**, and the sweep that reports them runs
 * nightly. From the day a second region opened, the Owner would have been
 * alerted every night about drift that did not exist and would have had to
 * override the close, with a written reason, every month. A guard that always
 * fires is a guard nobody reads — which is the exact sentence the period-close
 * work was built on.
 *
 * The bank is the one deliberate exception, and it has its own tests below.
 */
class BooksPerRegionTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'PR-1';

    private User $finance;

    private User $sales;

    private User $owner;

    private Region $sby;

    private Region $jkt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->travelTo('2026-08-20 09:00:00');

        $this->sby = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT']);

        $this->finance = User::factory()->role(Role::Finance)->create(['region_id' => $this->sby->id]);
        $this->sales = User::factory()->sales()->create();
        $this->owner = User::factory()->owner()->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        $v = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $v->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);
    }

    /** One region's whole trading life: stock in, sale out, money in. */
    private function tradeIn(Region $r, bool $settle = true): Invoice
    {
        return app(RegionContext::class)->within($r, function () use ($r, $settle) {
            $gudang = Warehouse::factory()->create(['kode' => 'GD-'.$r->kode]);
            $this->stockUp($gudang, 500);

            $company = Company::factory()->creditLimit(500_000_000)->create([
                'nama' => 'Bengkel '.$r->kode, 'status' => Company::STATUS_ACTIVE,
            ]);

            $order = Order::factory()->create([
                'company_id' => $company->id,
                'warehouse_id' => $gudang->id,
                'created_by' => $this->sales->id,
            ]);
            OrderLine::factory()->qty(10)->create([
                'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
            ]);

            $m = app(OrderStateMachine::class);
            $m->submit($order->refresh(), $this->sales);
            $m->confirm($order->refresh(), $this->owner);
            $m->awaitPayment($order->refresh(), $this->sales);

            $faktur = $order->refresh()->invoice()->firstOrFail();

            if ($settle) {
                app(PaymentLedger::class)->recordManualPayment(
                    company: $company,
                    amountRupiah: (int) $faktur->total_rupiah,
                    actor: $this->finance,
                    invoice: $faktur,
                );
            }

            return $faktur;
        });
    }

    private function stockUp(Warehouse $gudang, int $qty): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    // --- the ledger reads ----------------------------------------------------

    public function test_a_gl_balance_is_the_bound_regions_and_not_the_companys(): void
    {
        $this->tradeIn($this->sby);
        $this->tradeIn($this->jkt);

        $ledger = app(Ledger::class);
        $ctx = app(RegionContext::class);

        $sby = $ctx->within($this->sby, fn () => $ledger->balanceOf(AccountCode::PERSEDIAAN));
        $jkt = $ctx->within($this->jkt, fn () => $ledger->balanceOf(AccountCode::PERSEDIAAN));
        $semua = $ctx->acrossAll(fn () => $ledger->balanceOf(AccountCode::PERSEDIAAN));

        $this->assertSame(30_000_000, $sby);
        $this->assertSame(30_000_000, $jkt);

        // Unbound is the consolidation, and it is the sum of the parts.
        $this->assertSame($sby + $jkt, $semua);
    }

    /**
     * The whole point, stated as the property rather than as a number: two
     * regions that each traded cleanly have books that each add up.
     */
    public function test_every_control_account_agrees_inside_its_own_region(): void
    {
        $this->tradeIn($this->sby);
        $this->tradeIn($this->jkt);

        foreach ([$this->sby, $this->jkt] as $r) {
            app(RegionContext::class)->within($r, function () use ($r) {
                $keluar = array_map(
                    fn (ControlAccountCheck $c) => sprintf(
                        '%s %s: buku %d, subledger %d',
                        $c->kode, $c->nama, $c->buku, $c->subledger,
                    ),
                    app(LedgerReconciliation::class)->discrepancies(),
                );

                $this->assertSame([], $keluar, "{$r->kode} books must add up on their own");
            });
        }
    }

    public function test_the_nightly_integrity_sweep_is_silent(): void
    {
        // The sweep walks region by region. Before this, it reported drift on
        // every control account in every region, every night.
        $this->tradeIn($this->sby);
        $this->tradeIn($this->jkt);

        $integrity = app(LedgerIntegrity::class);

        foreach ([$this->sby, $this->jkt] as $r) {
            $this->assertSame(
                [],
                array_map(
                    fn ($t) => $t->pemeriksaan.' / '.$t->subjek.': '.$t->temuan,
                    $integrity->forRegion($r),
                ),
                "{$r->kode} must report nothing",
            );
        }
    }

    public function test_a_month_with_two_trading_regions_closes_without_an_override(): void
    {
        /*
         * The cost of the defect, in the place it would actually have been
         * paid. `buku` and `nilai_persediaan` are blocking findings, so a
         * close would have needed the Owner and a written reason — every
         * month, for drift that was not there.
         */
        $this->tradeIn($this->sby);
        $this->tradeIn($this->jkt);

        $this->travelTo('2026-09-02 09:00:00');

        foreach ([$this->sby, $this->jkt] as $r) {
            app(RegionContext::class)->within($r, function () use ($r) {
                $period = app(PeriodCloser::class)->close(2026, 8, $this->finance);

                $this->assertNotNull($period, "{$r->kode} must close on Finance's own authority");
            });
        }
    }

    /** A real drift is still caught — the check did not simply go quiet. */
    public function test_a_genuine_drift_in_one_region_is_still_reported(): void
    {
        $this->tradeIn($this->sby);
        $this->tradeIn($this->jkt);

        // Bill nobody for goods already received in Jakarta: the receipt
        // accrued Utang Belum Ditagih there and nothing has cleared it, so
        // moving the subledger out from under the ledger must show.
        app(RegionContext::class)->within($this->jkt, function () {
            GoodsReceipt::query()->update(['status' => 'draft']);
        });

        $jkt = app(RegionContext::class)->within(
            $this->jkt,
            fn () => app(LedgerReconciliation::class)->discrepancies(),
        );

        $sby = app(RegionContext::class)->within(
            $this->sby,
            fn () => app(LedgerReconciliation::class)->discrepancies(),
        );

        $this->assertNotSame([], $jkt, 'Jakarta must report the drift');
        $this->assertSame([], $sby, 'and Surabaya must not be dragged into it');
    }

    // --- the bank is the deliberate exception --------------------------------

    /**
     * A bank reconciliation proves a real account against a real statement,
     * and the bank has never heard of our regions: `bank_accounts` carries no
     * region, there is one company account, and it is the one printed on every
     * faktur. So the book balance it compares is the whole account's.
     */
    public function test_the_bank_reconciliation_reads_the_whole_account(): void
    {
        $this->tradeIn($this->sby);
        $this->tradeIn($this->jkt);

        $rekening = app(BankAccounts::class)->default();
        $ledger = app(Ledger::class);
        $ctx = app(RegionContext::class);

        $sby = $ctx->within($this->sby, fn () => $ledger->balanceOf(AccountCode::BANK));
        $jkt = $ctx->within($this->jkt, fn () => $ledger->balanceOf(AccountCode::BANK));

        $this->assertGreaterThan(0, $sby);
        $this->assertGreaterThan(0, $jkt);

        $rekonsiliasi = $ctx->within($this->sby, fn () => app(BankReconciler::class)->open(
            statementDate: today(),
            statementBalance: $sby + $jkt,
            actor: $this->finance,
            rekening: $rekening,
        ));

        $summary = $ctx->within(
            $this->sby,
            fn () => app(BankReconciler::class)->summarise($rekonsiliasi),
        );

        // Not Surabaya's share — every rupiah the bank is holding.
        $this->assertSame($sby + $jkt, $summary->saldoBuku);
    }

    public function test_one_account_gets_one_reconciliation_per_statement_date(): void
    {
        // Scoped, two regions could each open one for the same real account
        // and the same date without seeing the other: two half-proofs, and
        // neither would ever balance.
        $rekening = app(BankAccounts::class)->default();
        $ctx = app(RegionContext::class);

        $ctx->within($this->sby, fn () => app(BankReconciler::class)->open(
            statementDate: today(),
            statementBalance: 0,
            actor: $this->finance,
            rekening: $rekening,
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah ada/');

        $ctx->within($this->jkt, fn () => app(BankReconciler::class)->open(
            statementDate: today(),
            statementBalance: 0,
            actor: $this->finance,
            rekening: $rekening,
        ));
    }
}
