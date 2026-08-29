<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Reporting\PayablesAgeing;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payables mirror: whom we owe, by age — and the property that makes
 * it usable in a payment run, which is that it sums to Utang Usaha.
 */
class PayablesAgeingTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->finance()->create();
    }

    private function bill(Supplier $supplier, int $total, int $daysUntilDue): SupplierBill
    {
        return SupplierBill::factory()->create([
            'supplier_id' => $supplier->id,
            'total_rupiah' => $total,
            'due_date' => now()->addDays($daysUntilDue)->toDateString(),
            'status' => SupplierBill::STATUS_OPEN,
        ]);
    }

    public function test_bills_land_in_the_band_their_lateness_earns(): void
    {
        $s = Supplier::factory()->create(['nama' => 'PT Pemasok Umur']);

        $this->bill($s, 10_000_000, 10);    // not yet due
        $this->bill($s, 5_000_000, -15);    // 15 days late → 1–30
        $this->bill($s, 3_000_000, -75);    // 75 days late → 61–90
        $this->bill($s, 1_000_000, -200);   // ancient → > 90

        $row = app(PayablesAgeing::class)->build()->rows[0];

        $this->assertSame('PT Pemasok Umur', $row['dimensi']);
        $this->assertSame(10_000_000, $row['belum_jatuh_tempo']);
        $this->assertSame(5_000_000, $row['b1']);
        $this->assertSame(0, $row['b2']);
        $this->assertSame(3_000_000, $row['b3']);
        $this->assertSame(1_000_000, $row['b4']);
        $this->assertSame(19_000_000, $row['total']);
        $this->assertSame(200, $row['tertua']);
    }

    public function test_a_part_payment_shrinks_the_bill_in_its_band(): void
    {
        $s = Supplier::factory()->create();
        $bill = $this->bill($s, 10_000_000, -5);

        app(SupplierLedger::class)->recordPayment($s, 4_000_000, $this->finance, $bill);

        $row = app(PayablesAgeing::class)->build()->rows[0];

        $this->assertSame(6_000_000, $row['b1']);
        $this->assertSame(6_000_000, $row['total']);
    }

    public function test_an_unattached_payment_gets_its_own_column_not_a_guessed_band(): void
    {
        $s = Supplier::factory()->create();
        $this->bill($s, 10_000_000, 10);

        // Money sent ahead of the paperwork — belongs to no bill yet.
        app(SupplierLedger::class)->recordPayment($s, 2_500_000, $this->finance);

        $row = app(PayablesAgeing::class)->build()->rows[0];

        $this->assertSame(10_000_000, $row['belum_jatuh_tempo']);
        $this->assertSame(-2_500_000, $row['belum_terkait']);
        $this->assertSame(7_500_000, $row['total']);
    }

    /**
     * The property that makes the report a payment run can trust: its total
     * is the ledger's Utang Usaha, by construction — and when they diverge,
     * the report carries a PERIKSA note instead of a quiet wrong number.
     */
    public function test_the_report_total_ties_to_utang_usaha(): void
    {
        $a = Supplier::factory()->create();
        $b = Supplier::factory()->create();

        $this->bill($a, 12_000_000, -40);
        $billB = $this->bill($b, 8_000_000, 5);
        app(SupplierLedger::class)->recordPayment($b, 3_000_000, $this->finance, $billB);
        app(SupplierLedger::class)->recordPayment($a, 1_000_000, $this->finance);

        $table = app(PayablesAgeing::class)->build();

        $this->assertSame(
            app(SupplierLedger::class)->totalPayable(),
            array_sum(array_column($table->rows, 'total')),
        );
        $this->assertSame([], $table->catatan, 'A tied report carries no PERIKSA note.');

        // And the signed bucketTotals sum to the same figure.
        $this->assertSame(
            app(SupplierLedger::class)->totalPayable(),
            array_sum(array_column(app(PayablesAgeing::class)->bucketTotals($table), 'nilai')),
        );
    }

    public function test_only_purchase_side_roles_open_the_screen(): void
    {
        // The caption must name the payables control account, not the
        // receivables one the shared control partial was written for.
        $this->actingAs(User::factory()->owner()->create(), 'web')
            ->get('/admin/laporan/umur-hutang')->assertOk()
            ->assertSee('Hutang Usaha');

        $this->actingAs($this->finance, 'web')
            ->get('/admin/laporan/umur-hutang')->assertOk();

        // What we pay suppliers is not the selling side's lever.
        $this->actingAs(User::factory()->sales()->create(), 'web')
            ->get('/admin/laporan/umur-hutang')->assertForbidden();
    }
}
