<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Money;
use App\Filament\Portal\Resources\Katalog\Pages\ListKatalog;
use App\Models\Company;
use App\Models\CompanyPriceOverride;
use App\Models\CustomerUser;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Catalog with their prices" — the third item on the portal priority list, and
 * the whole reason a B2B portal exists rather than a public price page.
 *
 * A catalogue that quietly shows list price to every customer would look
 * completely correct in a browser as long as the buyer you tested with happens
 * to sit on a 0% tier, which the first one did. So the discount is asserted
 * here rather than eyeballed.
 */
class PortalCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-CAT-1';

    private const LIST_PRICE = 1_000_000;

    private PriceListVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');

        $this->version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        Product::factory()->create(['kode' => self::SKU, 'satuan_dasar' => 'PCS', 'aktif' => true]);
        PriceListItem::factory()->create([
            'version_id' => $this->version->id, 'kode' => self::SKU, 'harga' => self::LIST_PRICE,
        ]);
    }

    private function buyerOn(?PriceTier $tier): CustomerUser
    {
        $company = Company::factory()->create(['price_tier_id' => $tier?->id]);

        return CustomerUser::factory()->create(['company_id' => $company->id]);
    }

    private function catalogue(CustomerUser $buyer): string
    {
        $this->actingAs($buyer, 'customer');

        return Livewire::test(ListKatalog::class)->html();
    }

    public function test_a_buyer_with_no_tier_sees_the_list_price(): void
    {
        $html = $this->catalogue($this->buyerOn(null));

        $this->assertStringContainsString(Money::format(self::LIST_PRICE), $html);
    }

    /** The point of the whole screen: a discounted buyer sees their own number. */
    public function test_a_tiered_buyer_sees_their_discounted_price_not_the_list_price(): void
    {
        // 15% off.
        $tier = PriceTier::factory()->create(['discount_bps' => 1_500, 'aktif' => true]);

        $html = $this->catalogue($this->buyerOn($tier));

        $this->assertStringContainsString(Money::format(850_000), $html);
        $this->assertStringNotContainsString(Money::format(self::LIST_PRICE), $html);
    }

    public function test_a_company_override_beats_the_tier(): void
    {
        $tier = PriceTier::factory()->create(['discount_bps' => 1_500, 'aktif' => true]);
        $buyer = $this->buyerOn($tier);

        CompanyPriceOverride::factory()->create([
            'company_id' => $buyer->company_id,
            'kode' => self::SKU,
            'harga' => 700_000,
        ]);

        $html = $this->catalogue($buyer);

        $this->assertStringContainsString(Money::format(700_000), $html);
        $this->assertStringNotContainsString(Money::format(850_000), $html);
    }

    /**
     * Two customers on different tiers must not see each other's price, which
     * is the same rule as the order-history scoping and just as easy to get
     * wrong once a cache is involved.
     */
    public function test_two_buyers_on_different_tiers_see_different_prices(): void
    {
        $cheap = $this->buyerOn(PriceTier::factory()->create(['discount_bps' => 3_000, 'aktif' => true]));
        $dear = $this->buyerOn(PriceTier::factory()->create(['discount_bps' => 500, 'aktif' => true]));

        $this->assertStringContainsString(Money::format(700_000), $this->catalogue($cheap));
        $this->assertStringContainsString(Money::format(950_000), $this->catalogue($dear));
    }

    /** Never "Rp 0" for an unpriced SKU — that reads as free. */
    public function test_a_sku_with_no_published_price_says_to_ask(): void
    {
        Product::factory()->create(['kode' => 'YH-NOPRICE', 'aktif' => true]);

        $html = $this->catalogue($this->buyerOn(null));

        $this->assertStringContainsString('Hubungi kami', $html);
        $this->assertStringNotContainsString('Rp 0', $html);
    }

    public function test_inactive_products_are_not_listed(): void
    {
        Product::factory()->create(['kode' => 'YH-GONE', 'aktif' => false]);

        $html = $this->catalogue($this->buyerOn(null));

        $this->assertStringContainsString(self::SKU, $html);
        $this->assertStringNotContainsString('YH-GONE', $html);
    }

    /**
     * The catalogue prices a whole page in a fixed number of queries. Resolving
     * per row would be four queries per SKU on the screen buyers browse most.
     */
    public function test_the_catalogue_does_not_price_row_by_row(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $kode = 'YH-CAT-B'.$i;
            Product::factory()->create(['kode' => $kode, 'aktif' => true]);
            PriceListItem::factory()->create([
                'version_id' => $this->version->id, 'kode' => $kode, 'harga' => 200_000 + $i,
            ]);
        }

        $buyer = $this->buyerOn(null);
        $this->actingAs($buyer, 'customer');

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListKatalog::class)->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $pricing = array_filter(
            $log,
            fn (array $q) => (bool) preg_match('/\bprice_list_items\b/', $q['query']),
        );

        $this->assertLessThanOrEqual(
            2,
            count($pricing),
            'The catalogue is reading the price list once per row again.'
        );
    }
}
