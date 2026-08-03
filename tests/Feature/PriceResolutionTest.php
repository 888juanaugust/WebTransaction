<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\PriceReason;
use App\Domain\Pricing\PriceResolver;
use App\Models\Company;
use App\Models\CompanyPriceOverride;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\PriceTierItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * resolvePrice() is the only place a price is decided. Everything that shows a
 * number to a customer goes through it, so its precedence rules are pinned
 * down here.
 */
class PriceResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-1001';

    private function publishList(int $harga, string $effectiveFrom = '-1 day'): PriceListVersion
    {
        $version = PriceListVersion::factory()
            ->published()
            ->effectiveFrom(date('Y-m-d', strtotime($effectiveFrom)))
            ->create();

        PriceListItem::factory()->create([
            'version_id' => $version->id,
            'kode' => self::SKU,
            'harga' => $harga,
        ]);

        return $version;
    }

    private function resolver(): PriceResolver
    {
        return app(PriceResolver::class);
    }

    public function test_falls_back_to_the_published_list_price(): void
    {
        $version = $this->publishList(1_000_000);
        $company = Company::factory()->create();

        $result = $this->resolver()->resolve($company, self::SKU, 1);

        $this->assertSame(1_000_000, $result->unitPrice);
        $this->assertSame(PriceReason::ListPrice, $result->reason);
        $this->assertSame($version->id, $result->priceListVersionId);
    }

    public function test_returns_the_reason_alongside_the_price(): void
    {
        $this->publishList(1_000_000);

        $tier = PriceTier::factory()->discount(1000)->create();
        $company = Company::factory()->create(['price_tier_id' => $tier->id]);

        $result = $this->resolver()->resolve($company, self::SKU, 1);

        $this->assertSame(900_000, $result->unitPrice);
        $this->assertSame(PriceReason::TierBlanketDiscount, $result->reason);
        $this->assertSame(1000, $result->discountBps);
        $this->assertSame(1_000_000, $result->listPrice);
        $this->assertSame($tier->id, $result->meta['price_tier_id']);
    }

    public function test_company_override_beats_tier_discount(): void
    {
        $this->publishList(1_000_000);

        $tier = PriceTier::factory()->discount(1000)->create();
        $company = Company::factory()->create(['price_tier_id' => $tier->id]);

        CompanyPriceOverride::factory()->create([
            'company_id' => $company->id,
            'kode' => self::SKU,
            'harga' => 750_000,
        ]);

        $result = $this->resolver()->resolve($company, self::SKU, 1);

        $this->assertSame(750_000, $result->unitPrice);
        $this->assertSame(PriceReason::CompanyOverridePrice, $result->reason);
    }

    public function test_sku_specific_tier_item_beats_blanket_tier_discount(): void
    {
        $this->publishList(1_000_000);

        $tier = PriceTier::factory()->discount(1000)->create();
        $company = Company::factory()->create(['price_tier_id' => $tier->id]);

        PriceTierItem::factory()->create([
            'price_tier_id' => $tier->id,
            'kode' => self::SKU,
            'discount_bps' => 2500,
            'min_qty_base' => 1,
        ]);

        $result = $this->resolver()->resolve($company, self::SKU, 1);

        $this->assertSame(750_000, $result->unitPrice);
        $this->assertSame(PriceReason::TierItemDiscount, $result->reason);
    }

    public function test_quantity_break_applies_only_once_the_quantity_is_reached(): void
    {
        $this->publishList(1_000_000);

        $tier = PriceTier::factory()->create();
        $company = Company::factory()->create(['price_tier_id' => $tier->id]);

        PriceTierItem::factory()->create([
            'price_tier_id' => $tier->id,
            'kode' => self::SKU,
            'min_qty_base' => 50,
            'discount_bps' => 1500,
        ]);

        $below = $this->resolver()->resolve($company, self::SKU, 49);
        $this->assertSame(1_000_000, $below->unitPrice);
        $this->assertSame(PriceReason::ListPrice, $below->reason);

        $at = $this->resolver()->resolve($company, self::SKU, 50);
        $this->assertSame(850_000, $at->unitPrice);
        $this->assertSame(PriceReason::TierItemDiscount, $at->reason);
    }

    public function test_the_highest_reached_quantity_break_wins(): void
    {
        $this->publishList(1_000_000);

        $tier = PriceTier::factory()->create();
        $company = Company::factory()->create(['price_tier_id' => $tier->id]);

        foreach ([[1, 0], [50, 1000], [100, 2000]] as [$minQty, $bps]) {
            PriceTierItem::factory()->create([
                'price_tier_id' => $tier->id,
                'kode' => self::SKU,
                'min_qty_base' => $minQty,
                'discount_bps' => $bps,
            ]);
        }

        $this->assertSame(1_000_000, $this->resolver()->resolve($company, self::SKU, 10)->unitPrice);
        $this->assertSame(900_000, $this->resolver()->resolve($company, self::SKU, 60)->unitPrice);
        $this->assertSame(800_000, $this->resolver()->resolve($company, self::SKU, 250)->unitPrice);
    }

    public function test_it_prices_against_the_version_effective_on_the_given_date(): void
    {
        $old = $this->publishList(1_000_000, '-30 days');

        $newVersion = PriceListVersion::factory()
            ->published()
            ->effectiveFrom(date('Y-m-d', strtotime('-2 days')))
            ->create();

        PriceListItem::factory()->create([
            'version_id' => $newVersion->id,
            'kode' => self::SKU,
            'harga' => 1_200_000,
        ]);

        $company = Company::factory()->create();

        $today = $this->resolver()->resolve($company, self::SKU, 1);
        $this->assertSame(1_200_000, $today->unitPrice);
        $this->assertSame($newVersion->id, $today->priceListVersionId);

        // Re-pricing a historical date must still find the old version.
        $backThen = $this->resolver()->resolve(
            $company,
            self::SKU,
            1,
            new \DateTimeImmutable('-10 days'),
        );
        $this->assertSame(1_000_000, $backThen->unitPrice);
        $this->assertSame($old->id, $backThen->priceListVersionId);
    }

    public function test_a_future_dated_version_does_not_price_today(): void
    {
        $current = $this->publishList(1_000_000, '-1 day');

        $future = PriceListVersion::factory()
            ->published()
            ->effectiveFrom(date('Y-m-d', strtotime('+7 days')))
            ->create();

        PriceListItem::factory()->create([
            'version_id' => $future->id,
            'kode' => self::SKU,
            'harga' => 2_000_000,
        ]);

        $result = $this->resolver()->resolve(Company::factory()->create(), self::SKU, 1);

        $this->assertSame(1_000_000, $result->unitPrice);
        $this->assertSame($current->id, $result->priceListVersionId);
    }

    public function test_a_draft_version_is_never_used_for_pricing(): void
    {
        $this->publishList(1_000_000);

        $draft = PriceListVersion::factory()->create(['effective_from' => now()->toDateString()]);
        PriceListItem::factory()->create([
            'version_id' => $draft->id,
            'kode' => self::SKU,
            'harga' => 9_999_999,
        ]);

        $result = $this->resolver()->resolve(Company::factory()->create(), self::SKU, 1);

        $this->assertSame(1_000_000, $result->unitPrice);
    }

    public function test_expired_overrides_are_ignored(): void
    {
        $this->publishList(1_000_000);
        $company = Company::factory()->create();

        CompanyPriceOverride::factory()->create([
            'company_id' => $company->id,
            'kode' => self::SKU,
            'harga' => 500_000,
            'effective_from' => now()->subDays(30)->toDateString(),
            'effective_until' => now()->subDay()->toDateString(),
        ]);

        $result = $this->resolver()->resolve($company, self::SKU, 1);

        $this->assertSame(1_000_000, $result->unitPrice);
        $this->assertSame(PriceReason::ListPrice, $result->reason);
    }

    public function test_an_unknown_sku_is_unpriced_rather_than_free(): void
    {
        $this->publishList(1_000_000);

        $result = $this->resolver()->resolve(Company::factory()->create(), 'DOES-NOT-EXIST', 1);

        $this->assertFalse($result->isPriced());
        $this->assertSame(PriceReason::NotPriced, $result->reason);
        $this->assertNull($result->unitPrice);
    }

    public function test_requiring_a_price_that_does_not_exist_throws(): void
    {
        $this->publishList(1_000_000);

        $result = $this->resolver()->resolve(Company::factory()->create(), 'DOES-NOT-EXIST', 1);

        // Silently becoming zero is how a customer gets a free carton.
        $this->expectException(RuntimeException::class);
        $result->requireUnitPrice();
    }

    public function test_an_inactive_sku_is_unpriced(): void
    {
        $version = $this->publishList(1_000_000);

        PriceListItem::query()
            ->where('version_id', $version->id)
            ->where('kode', self::SKU)
            ->update(['aktif' => false]);

        $result = $this->resolver()->resolve(Company::factory()->create(), self::SKU, 1);

        $this->assertFalse($result->isPriced());
    }

    public function test_with_no_published_list_at_all_nothing_is_priced(): void
    {
        $result = $this->resolver()->resolve(Company::factory()->create(), self::SKU, 1);

        $this->assertFalse($result->isPriced());
        $this->assertStringContainsString('no published price list', $result->meta['why']);
    }

    public function test_line_total_multiplies_by_base_quantity(): void
    {
        $this->publishList(250_000);

        $result = $this->resolver()->resolve(Company::factory()->create(), self::SKU, 24);

        $this->assertSame(6_000_000, $result->lineTotal(24));
    }
}
