<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Credit\CreditChecker;
use App\Domain\Credit\DebtAging;
use App\Domain\Regions\RegionContext;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer's exposure spans regions.
 *
 * Split orders book each part in the shipping warehouse's region, so "what
 * does this customer owe" and "are they frozen" must aggregate across every
 * set of books — and the buyer's own portal must show documents wherever
 * they landed. The regional books stay separate; the customer does not.
 */
class CrossRegionExposureTest extends TestCase
{
    use RefreshDatabase;

    private Region $sby;

    private Company $toko;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sby = Region::factory()->create(['kode' => 'SBY']);
        $this->toko = Company::factory()->creditLimit(100_000_000)->create();
    }

    private function invoiceIn(Region $region, int $total, int $umurHari = 10): Invoice
    {
        return app(RegionContext::class)->within($region, fn () => Invoice::factory()
            ->totalling($total)
            ->create([
                'company_id' => $this->toko->id,
                'issued_on' => today()->subDays($umurHari),
                'due_date' => today()->subDays($umurHari)->addDays(30),
            ]));
    }

    public function test_exposure_sums_invoices_from_every_region(): void
    {
        $this->invoiceIn($this->currentRegion(), 10_000_000);
        $this->invoiceIn($this->sby, 25_000_000);

        $status = app(CreditChecker::class)->status($this->toko);

        // Read from the home region, the Surabaya paper still counts.
        $this->assertSame(35_000_000, $status->outstanding);
    }

    public function test_an_aged_invoice_in_another_region_freezes_the_customer_everywhere(): void
    {
        $this->invoiceIn($this->sby, 5_000_000, umurHari: 151);

        $this->assertTrue(app(DebtAging::class)->isFrozen($this->toko));
        $this->assertSame(
            5_000_000,
            app(DebtAging::class)->fallDueInvoices($this->toko)->first()->total_rupiah,
        );
    }

    public function test_the_buyer_reads_their_documents_from_every_regions_books(): void
    {
        $this->invoiceIn($this->sby, 7_000_000);
        app(RegionContext::class)->within(
            $this->sby,
            fn () => Order::factory()->create(['company_id' => $this->toko->id]),
        );

        $buyer = CustomerUser::factory()->create(['company_id' => $this->toko->id]);
        $this->actingAs($buyer, 'customer');

        // Pinned to home region — yet the read scope steps aside for the
        // customer guard, because a buyer is company-scoped, not
        // region-scoped.
        app(RegionContext::class)->pinTo($this->currentRegion());

        $this->assertSame(1, Invoice::query()->where('company_id', $this->toko->id)->count());
        $this->assertSame(1, Order::query()->where('company_id', $this->toko->id)->count());
    }

    public function test_staff_reads_stay_region_scoped(): void
    {
        $this->invoiceIn($this->currentRegion(), 10_000_000);
        $this->invoiceIn($this->sby, 25_000_000);

        // No buyer in session: the scope filters as it always has, so one
        // region's AR queue never shows another's paper.
        $this->assertSame(1, Invoice::query()->count());
    }
}
