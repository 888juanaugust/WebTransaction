<?php

namespace Tests\Feature\Client;

use App\Client\Domain\Pricing\CentralPrices;
use App\Client\Domain\Pricing\PriceReason;
use App\Client\Models\CustomerPriceRule;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Domain\Sales\Contracts\Prices;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;
use App\Models\Sales\SellingPriceAdjustment;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Customer rules first, then the tier's item rules, then the tier's blanket discount, then the list in force, then the base. */
class PriceResolutionTest extends TestCase
{
    private Customer $customer;

    private Item $item;

    private PriceCategory $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->tier = PriceCategory::query()->where('is_default', true)->firstOrFail();
        $this->customer = $this->sampleCustomer(['price_category_id' => $this->tier->id]);
        $this->item = $this->sampleItem(['sell_price' => 150_000, 'use_wholesale_price' => true])->fresh();
    }

    private function version(int $price, string $from = '2026-10-01', string $status = PriceListVersion::PUBLISHED, bool $active = true): PriceListVersion
    {
        $version = PriceListVersion::query()->create(['effective_from' => $from, 'status' => $status, 'published_at' => $status === PriceListVersion::DRAFT ? null : now(), 'published_by' => auth()->id()]);
        PriceListItem::query()->create(['version_id' => $version->id, 'item_id' => $this->item->id, 'price' => $price, 'qty_per_ctn' => 12, 'is_active' => $active]);

        return $version;
    }

    private function resolve(?string $qty = null, ?string $date = null, ?int $unitId = null): array
    {
        return app(Prices::class)->resolve($this->customer->fresh(), $this->item->fresh(), $unitId, $date, $qty);
    }

    public function test_central_prices_is_bound_as_the_price_source(): void
    {
        $this->assertInstanceOf(CentralPrices::class, app(Prices::class));
    }

    public function test_the_list_price_of_the_version_in_force_prices_the_line(): void
    {
        $version = $this->version(120_000);

        $answer = $this->resolve();
        $this->assertSame('120000.0000', $answer['price']);
        $this->assertSame(PriceReason::ListPrice->value, $answer['reason']);
        $this->assertSame($version->id, $answer['version_id']);
    }

    public function test_without_a_version_the_base_price_applies_and_a_zero_is_unpriced(): void
    {
        $this->assertSame(PriceReason::BasePrice->value, $this->resolve()['reason']);
        $this->assertSame('150000.0000', $this->resolve()['price']);

        $this->item->update(['sell_price' => 0]);
        $this->assertSame(PriceReason::Unpriced->value, $this->resolve()['reason']);
    }

    public function test_a_customer_price_beats_everything(): void
    {
        $this->version(120_000);
        $this->tier->update(['blanket_discount_percent' => 10]);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 95_000, 'reason' => 'tender']);

        $answer = $this->resolve();
        $this->assertSame('95000.0000', $answer['price']);
        $this->assertSame('0.0000', $answer['discount_percent']);
        $this->assertSame(PriceReason::CustomerPrice->value, $answer['reason']);
    }

    public function test_a_customer_blanket_discount_beats_the_tiers_item_rule(): void
    {
        $this->version(120_000);
        $adjustment = SellingPriceAdjustment::query()->create(['number' => 'PA-1', 'price_category_id' => $this->tier->id, 'sales_adjustment_type' => SellingPriceAdjustment::PRICE, 'trans_date' => '2026-10-01']);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'min_quantity' => 0, 'value' => 110_000]);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => null, 'discount_percent' => 5, 'reason' => 'loyal']);

        $answer = $this->resolve();
        $this->assertSame('120000.0000', $answer['price'], 'the discount is off the list price');
        $this->assertSame('5.0000', $answer['discount_percent']);
        $this->assertSame(PriceReason::CustomerDiscount->value, $answer['reason']);
    }

    public function test_the_customers_item_rule_beats_its_blanket_rule_and_quantity_breaks_apply_in_order(): void
    {
        $this->version(120_000);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => null, 'discount_percent' => 5, 'reason' => 'loyal']);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'min_base_quantity' => 50, 'price' => 100_000, 'reason' => 'bulk']);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'min_base_quantity' => 100, 'price' => 90_000, 'reason' => 'pallet']);

        $this->assertSame(PriceReason::CustomerDiscount->value, $this->resolve('10')['reason'], 'below every break of the item rule, the blanket rule');
        $this->assertSame('100000.0000', $this->resolve('50')['price']);
        $this->assertSame('90000.0000', $this->resolve('120')['price']);
    }

    public function test_the_tiers_item_rule_beats_its_blanket_discount(): void
    {
        $this->version(120_000);
        $this->tier->update(['blanket_discount_percent' => 10]);
        $adjustment = SellingPriceAdjustment::query()->create(['number' => 'PA-1', 'price_category_id' => $this->tier->id, 'sales_adjustment_type' => SellingPriceAdjustment::PRICE, 'trans_date' => '2026-10-01']);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'min_quantity' => 0, 'value' => 110_000]);

        $answer = $this->resolve();
        $this->assertSame('110000.0000', $answer['price']);
        $this->assertSame(PriceReason::TierPrice->value, $answer['reason']);
    }

    public function test_the_tiers_blanket_discount_is_off_the_list_price(): void
    {
        $version = $this->version(120_000);
        $this->tier->update(['blanket_discount_percent' => 10]);

        $answer = $this->resolve();
        $this->assertSame('120000.0000', $answer['price']);
        $this->assertSame('10.0000', $answer['discount_percent']);
        $this->assertSame(PriceReason::TierDiscount->value, $answer['reason']);
        $this->assertSame($version->id, $answer['version_id']);

        $this->item->update(['default_discount' => 15]);
        $this->assertSame('15.0000', $this->resolve()['discount_percent'], 'the larger discount applies');
    }

    public function test_the_version_in_force_on_the_date_prices_and_a_draft_or_future_one_never_does(): void
    {
        $old = $this->version(100_000, '2026-09-01', PriceListVersion::SUPERSEDED);
        $new = $this->version(120_000, '2026-10-01');
        $this->version(200_000, '2026-11-01');
        $this->version(999_000, '2026-10-01', PriceListVersion::DRAFT);

        $this->assertSame($new->id, $this->resolve()['version_id']);
        $this->assertSame('100000.0000', $this->resolve(null, '2026-09-15')['price']);
        $this->assertSame($old->id, $this->resolve(null, '2026-09-15')['version_id']);
        $this->assertSame(PriceReason::BasePrice->value, $this->resolve(null, '2026-08-01')['reason'], 'before any version');
    }

    public function test_an_item_inactive_in_the_version_and_an_expired_rule_are_ignored(): void
    {
        $this->version(120_000, active: false);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 95_000, 'effective_until' => '2026-10-01', 'reason' => 'old deal']);

        $answer = $this->resolve();
        $this->assertSame(PriceReason::BasePrice->value, $answer['reason']);
        $this->assertSame('150000.0000', $answer['price']);
    }

    public function test_prices_scale_to_the_lines_unit(): void
    {
        $this->version(120_000);
        $carton = Unit::query()->where('name', 'CTN')->firstOrFail();
        $this->item->units()->create(['sort' => 0, 'unit_id' => $carton->id, 'ratio' => 12, 'sell_price' => 0]);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 95_000, 'reason' => 'tender']);

        $this->assertSame('1140000.0000', $this->resolve(null, null, $carton->id)['price']);
        CustomerPriceRule::query()->delete();
        $this->assertSame('1440000.0000', $this->resolve(null, null, $carton->id)['price']);
    }

    public function test_the_selling_price_guard_reads_the_same_source(): void
    {
        $this->version(120_000);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 95_000, 'reason' => 'tender']);

        $this->assertSame('95000.0000', app(Prices::class)->resolve($this->customer, $this->item, null, '2026-10-17', '1')['price']);
    }
}
