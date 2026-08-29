<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Quotes\QuotationFlow;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penawaran: prices promised in writing, resolved by the one resolver —
 * and the promise's limits: it expires, and it becomes at most one order,
 * which prices itself again at confirmed like every order does.
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->company = Company::factory()->create();

        Product::factory()->create([
            'kode' => 'QT-1', 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'description' => 'Shock uji',
        ]);

        $version = PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => 'QT-1', 'harga' => 100_000,
        ]);
    }

    private function draft(): Quotation
    {
        return app(QuotationFlow::class)->draft(
            $this->company,
            [['sku' => 'QT-1', 'qty' => 2, 'unit' => 'CTN']],
            $this->sales,
        );
    }

    public function test_lines_are_priced_by_the_resolver_not_typed(): void
    {
        $q = $this->draft();

        $line = $q->lines->sole();

        // 2 cartons of 10 at the 100k list price.
        $this->assertSame(20, $line->qty_base);
        $this->assertSame(100_000, (int) $line->unit_price_rupiah);
        $this->assertSame(2_000_000, (int) $line->line_total_rupiah);
        // DPP is 11/12 of the sale under PMK 131/2024; PPN follows it.
        $this->assertSame((int) round(2_000_000 * 11 / 12), (int) $line->dpp_rupiah);
        $this->assertSame(2_000_000 + (int) $line->ppn_rupiah, (int) $q->total_rupiah);

        $this->assertSame(1, AuditLog::query()->where('action', 'quotation_drafted')->count());
        $this->assertStringStartsWith('PEN-', $q->nomor);
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $q = $this->draft();
        app(QuotationFlow::class)->send($q, $this->sales);

        $q->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

        $this->assertSame('kedaluwarsa', $q->refresh()->statusTampil());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/kedaluwarsa/');

        app(QuotationFlow::class)->accept($q, $this->sales);
    }

    public function test_an_accepted_quote_becomes_exactly_one_draft_order_without_prices(): void
    {
        $flow = app(QuotationFlow::class);

        $q = $this->draft();
        $flow->send($q, $this->sales);
        $flow->accept($q, $this->sales);

        $gudang = Warehouse::factory()->create();
        $order = $flow->toOrder($q, $gudang, $this->sales);

        $this->assertSame('draft', $order->status->value);
        $this->assertSame($q->company_id, (int) $order->company_id);
        $this->assertStringContainsString($q->nomor, (string) $order->catatan);

        // The goods travel; the prices do not — invariant 3 belongs to
        // confirmed, and the quote must not pre-empt it.
        $line = $order->lines()->sole();
        $this->assertSame(20, (int) $line->qty_base);
        $this->assertNull($line->unit_price_rupiah);

        // Once, and at most once.
        $this->expectException(DomainException::class);
        $flow->toOrder($q->refresh(), $gudang, $this->sales);
    }

    public function test_a_warehouse_account_cannot_quote(): void
    {
        $this->expectException(DomainException::class);

        app(QuotationFlow::class)->draft(
            $this->company,
            [['sku' => 'QT-1', 'qty' => 1, 'unit' => 'PCS']],
            User::factory()->warehouse()->create(),
        );
    }

    public function test_the_printed_document_is_staff_only_and_marks_a_draft(): void
    {
        $q = $this->draft();

        $this->actingAs($this->sales, 'web')
            ->get("/dokumen/penawaran/{$q->id}")
            ->assertOk()
            ->assertSee($q->nomor)
            ->assertSee('DRAF — BELUM DIKIRIM', escape: false)
            ->assertSee('berlaku sampai', escape: false);

        $this->actingAs(User::factory()->warehouse()->create(), 'web')
            ->get("/dokumen/penawaran/{$q->id}")
            ->assertForbidden();
    }

    public function test_the_screen_opens_for_credit_roles(): void
    {
        $this->actingAs($this->sales, 'web')
            ->get('/admin/quotations')->assertOk();

        $this->actingAs(User::factory()->warehouse()->create(), 'web')
            ->get('/admin/quotations')->assertForbidden();
    }
}
