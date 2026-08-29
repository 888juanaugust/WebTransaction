<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cart\CartService;
use App\Domain\Cart\CartTotals;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Uom\Unit;
use App\Jobs\PruneAbandonedCarts;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The basket.
 *
 * Two things here are worth more than the CRUD: that a cart never stores a
 * price, and that checking out twice cannot produce two orders. The first keeps
 * `resolvePrice()` the only thing that decides a number; the second is the
 * difference between a double-clicked button and a customer billed twice.
 */
class CartTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-CART-1';

    private const LIST_PRICE = 500_000;

    private const QTY_PER_CTN = 12;

    private Company $company;

    private CustomerUser $buyer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->creditLimit(900_000_000)->create();
        $this->buyer = CustomerUser::factory()->create(['company_id' => $this->company->id]);
        $this->warehouse = Warehouse::factory()->create(['aktif' => true]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        Product::factory()->create([
            'kode' => self::SKU,
            'satuan_dasar' => 'PCS',
            'qty_per_ctn' => self::QTY_PER_CTN,
            'aktif' => true,
        ]);

        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => self::LIST_PRICE,
        ]);

        app(StockLedger::class)->record(self::SKU, $this->warehouse->id, 1_000, MovementReason::Penerimaan);
    }

    private function cart(): CartService
    {
        return app(CartService::class);
    }

    // --- the invariant that matters most ------------------------------------

    /**
     * Not one money column, in either table.
     *
     * A rupiah figure stored in a basket is a second source of truth for a
     * number that resolvePrice() owns and the order line snapshots at
     * `confirmed` — stale the moment a price list is published, and exactly the
     * figure a customer would quote back at us.
     */
    public function test_the_cart_schema_holds_no_prices(): void
    {
        // First prove the detector detects: order_lines is full of money, and
        // a pattern that matched nothing would pass this test silently.
        $this->assertNotEmpty(
            array_filter(
                Schema::getColumnListing('order_lines'),
                fn (string $column) => (bool) preg_match('/rupiah|harga|price|discount|total|ppn|dpp/i', $column),
            ),
            'The money-column pattern matches nothing, so the assertion below proves nothing.'
        );

        foreach (['carts', 'cart_items'] as $table) {
            $money = array_filter(
                Schema::getColumnListing($table),
                fn (string $column) => (bool) preg_match('/rupiah|harga|price|discount|total|ppn|dpp/i', $column),
            );

            $this->assertSame(
                [],
                array_values($money),
                "{$table} has a money column: ".implode(', ', $money).'. A cart holds what was '
                .'asked for, not what it costs.'
            );
        }
    }

    public function test_adding_to_the_cart_stores_only_what_was_asked_for(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);

        $row = DB::table('cart_items')->first();

        $this->assertEqualsCanonicalizing(
            ['id', 'cart_id', 'sku', 'ordered_unit', 'ordered_qty', 'created_at', 'updated_at'],
            array_keys((array) $row),
        );
    }

    // --- basket mechanics ---------------------------------------------------

    public function test_adding_the_same_sku_and_unit_twice_makes_one_line(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5);
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 3);

        $items = $this->cart()->forBuyer($this->buyer)->items;

        $this->assertCount(1, $items);
        $this->assertSame(8, $items->first()->ordered_qty);
    }

    /** The same SKU in PCS and in DUS are genuinely different lines. */
    public function test_the_same_sku_in_a_different_unit_is_its_own_line(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5);
        $this->cart()->add($this->buyer, self::SKU, Unit::Ctn, 2);

        $this->assertCount(2, $this->cart()->forBuyer($this->buyer)->items);
    }

    public function test_setting_a_quantity_to_zero_removes_the_line(): void
    {
        $item = $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5);

        $this->cart()->setQuantity($this->buyer, $item, 0);

        $this->assertSame(0, $this->cart()->forBuyer($this->buyer)->items()->count());
    }

    public function test_a_buyer_gets_one_cart_however_often_they_ask(): void
    {
        $first = $this->cart()->forBuyer($this->buyer);
        $second = $this->cart()->forBuyer($this->buyer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Cart::query()->count());
    }

    public function test_an_inactive_product_cannot_be_added(): void
    {
        Product::query()->where('kode', self::SKU)->update(['aktif' => false]);

        $this->expectException(DomainException::class);

        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 1);
    }

    /**
     * Asking for SET of a PCS product is a bug, not a conversion. Catching it
     * on add means an impossible line never sits in a basket waiting to fail
     * at checkout.
     */
    public function test_an_impossible_unit_is_refused_on_add_not_at_checkout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cart()->add($this->buyer, self::SKU, Unit::Set, 1);
    }

    // --- isolation ----------------------------------------------------------

    public function test_a_buyer_cannot_touch_another_buyers_cart_line(): void
    {
        $theirBuyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $theirItem = $this->cart()->add($theirBuyer, self::SKU, Unit::Pcs, 5);

        $this->expectException(DomainException::class);

        $this->cart()->setQuantity($this->buyer, $theirItem, 99);
    }

    public function test_a_buyer_cannot_remove_another_buyers_cart_line(): void
    {
        $theirBuyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $theirItem = $this->cart()->add($theirBuyer, self::SKU, Unit::Pcs, 5);

        try {
            $this->cart()->remove($this->buyer, $theirItem);
            $this->fail('A buyer must not be able to delete another buyer\'s cart line.');
        } catch (DomainException) {
            $this->assertDatabaseHas('cart_items', ['id' => $theirItem->id]);
        }
    }

    /** Two logins at the same bengkel each fill their own basket. */
    public function test_two_logins_at_one_company_have_separate_carts(): void
    {
        $colleague = CustomerUser::factory()->create(['company_id' => $this->company->id]);

        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5);

        $this->assertSame(0, $this->cart()->forBuyer($colleague)->items()->count());
        $this->assertSame(5, $this->cart()->forBuyer($this->buyer)->items()->first()->ordered_qty);
    }

    // --- checkout -----------------------------------------------------------

    public function test_checkout_creates_a_submitted_order_and_empties_the_cart(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);
        $this->cart()->add($this->buyer, self::SKU, Unit::Ctn, 2);

        $order = $this->cart()->checkout($this->buyer, 'PO-123', 'kirim pagi');

        $this->assertSame(OrderStatus::Submitted, $order->status);
        $this->assertSame('PO-123', $order->po_pelanggan);
        $this->assertSame($this->buyer->id, $order->placed_by_customer_user_id);
        $this->assertNull($order->created_by);
        $this->assertSame(2, $order->lines()->count());

        $this->assertTrue($this->cart()->forBuyer($this->buyer)->isEmpty());
    }

    /**
     * The point of the boundary: a basket becomes a *proposal*. It is not
     * priced, holds no stock, and has passed no credit check — all three
     * happen at `confirmed`, which is staff work.
     */
    public function test_a_cart_order_commits_nothing(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);

        $order = $this->cart()->checkout($this->buyer);

        $this->assertNull($order->confirmed_at);
        $this->assertSame(0, $order->total_rupiah);
        $this->assertSame(0, $order->reservations()->count());

        foreach ($order->lines as $line) {
            $this->assertFalse($line->isPriced());
        }
    }

    /** Base quantity is derived from the product at checkout, never stored. */
    public function test_checkout_converts_cartons_using_the_products_current_pack_size(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Ctn, 2);

        // The pack size changes while the item sits in the basket.
        Product::query()->where('kode', self::SKU)->update(['qty_per_ctn' => 20]);

        $order = $this->cart()->checkout($this->buyer);
        $line = $order->lines()->firstOrFail();

        $this->assertSame(40, $line->qty_base, '2 cartons of 20, not the 24 it would have been');
        $this->assertSame(20, $line->qty_per_ctn_snapshot);
    }

    public function test_checking_out_an_empty_cart_is_refused(): void
    {
        $this->expectException(DomainException::class);

        $this->cart()->checkout($this->buyer);
    }

    /**
     * A double-clicked button must not become two orders on a customer's
     * account. The cart is emptied in the same transaction that creates the
     * order, so the second attempt finds nothing to buy.
     */
    public function test_checking_out_twice_does_not_produce_two_orders(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);

        $first = $this->cart()->checkout($this->buyer);

        try {
            $this->cart()->checkout($this->buyer);
            $this->fail('A second checkout of the same basket must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kosong', $e->getMessage());
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($first->id, Order::query()->value('id'));
    }

    public function test_a_suspended_company_cannot_check_out(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5);

        $this->company->forceFill(['status' => Company::STATUS_SUSPENDED])->save();

        $this->expectException(DomainException::class);

        $this->cart()->checkout($this->buyer->refresh());
    }

    /** A refused checkout must leave the basket intact, not half-emptied. */
    public function test_a_refused_checkout_leaves_the_cart_alone(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5);
        $this->company->forceFill(['status' => Company::STATUS_SUSPENDED])->save();

        try {
            $this->cart()->checkout($this->buyer->refresh());
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(1, $this->cart()->forBuyer($this->buyer)->items()->count());
        $this->assertSame(0, Order::query()->count());
    }

    // --- indicative figures -------------------------------------------------

    public function test_the_estimate_prices_the_basket_without_storing_anything(): void
    {
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);
        $this->cart()->add($this->buyer, self::SKU, Unit::Ctn, 2);

        $estimate = app(CartTotals::class)->for($this->cart()->forBuyer($this->buyer));

        // 10 + 24 = 34 base units at 500.000
        $this->assertSame(17_000_000, $estimate->subtotal);
        // Effective 11% under PMK 131/2024.
        $this->assertSame(1_870_000, $estimate->ppn);
        $this->assertSame(18_870_000, $estimate->total);
        $this->assertTrue($estimate->fullyPriced);

        // And the database is untouched by having looked.
        $this->assertSame(
            [10, 2],
            CartItem::query()->orderBy('id')->pluck('ordered_qty')->all(),
        );
    }

    public function test_the_estimate_uses_the_buyers_tier_not_the_list_price(): void
    {
        $tier = PriceTier::factory()->create(['discount_bps' => 2_000, 'aktif' => true]);
        $this->company->forceFill(['price_tier_id' => $tier->id])->save();

        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);

        $estimate = app(CartTotals::class)->for($this->cart()->forBuyer($this->buyer->refresh()));

        // 20% off 500.000 = 400.000 × 10
        $this->assertSame(4_000_000, $estimate->subtotal);
    }

    public function test_an_unpriced_line_is_flagged_rather_than_counted_as_free(): void
    {
        Product::factory()->create(['kode' => 'YH-NOPRICE', 'aktif' => true, 'satuan_dasar' => 'PCS']);

        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 10);
        $this->cart()->add($this->buyer, 'YH-NOPRICE', Unit::Pcs, 3);

        $estimate = app(CartTotals::class)->for($this->cart()->forBuyer($this->buyer));

        $this->assertFalse($estimate->fullyPriced);
        // The priced line still counts; the unpriced one contributes nothing
        // rather than contributing zero rupiah of value.
        $this->assertSame(5_000_000, $estimate->subtotal);
        $this->assertNotEmpty($estimate->problems());
    }

    public function test_short_stock_is_a_warning_not_a_refusal(): void
    {
        // Ask for more than the 1.000 on hand.
        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 5_000);

        $estimate = app(CartTotals::class)->for($this->cart()->forBuyer($this->buyer));

        $this->assertNotEmpty($estimate->problems());

        // Still submittable: stock is decided at `confirmed`, and refusing an
        // order the warehouse could fill by then is worse than a warning.
        $order = $this->cart()->checkout($this->buyer);
        $this->assertSame(OrderStatus::Submitted, $order->status);
    }

    public function test_exceeding_the_credit_limit_is_a_warning_not_a_refusal(): void
    {
        $this->company->forceFill(['credit_limit_rupiah' => 1_000_000])->save();

        $this->cart()->add($this->buyer, self::SKU, Unit::Pcs, 100);

        $estimate = app(CartTotals::class)->for($this->cart()->forBuyer($this->buyer->refresh()));

        $this->assertTrue($estimate->exceedsCredit());

        $order = $this->cart()->checkout($this->buyer);
        $this->assertSame(OrderStatus::Submitted, $order->status);
    }

    /** Pricing the basket must not cost one round of queries per line. */
    public function test_the_estimate_does_not_price_line_by_line(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $kode = 'YH-CART-B'.$i;
            Product::factory()->create(['kode' => $kode, 'aktif' => true, 'satuan_dasar' => 'PCS']);
            PriceListItem::factory()->create([
                'version_id' => PriceListVersion::query()->value('id'),
                'kode' => $kode,
                'harga' => 100_000,
            ]);
            $this->cart()->add($this->buyer, $kode, Unit::Pcs, 2);
        }

        $cart = $this->cart()->forBuyer($this->buyer);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CartTotals::class)->for($cart);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $pricing = array_filter(
            $log,
            fn (array $q) => (bool) preg_match('/\bprice_list_items\b/', $q['query']),
        );

        $this->assertLessThanOrEqual(2, count($pricing));
    }

    // --- abandoned carts ----------------------------------------------------

    public function test_the_prune_deletes_a_basket_nobody_touched_for_three_months(): void
    {
        $stale = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $stale->id, 'sku' => 'YH-CART-1']);
        // Backdate below the model so `$touches` cannot refresh it.
        Cart::query()->whereKey($stale->id)->update(['updated_at' => now()->subDays(91)]);

        $fresh = Cart::factory()->create();

        (new PruneAbandonedCarts)->handle();

        $this->assertDatabaseMissing('carts', ['id' => $stale->id]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $stale->id]);
        $this->assertDatabaseHas('carts', ['id' => $fresh->id]);
    }

    public function test_editing_an_item_keeps_the_basket_alive(): void
    {
        /*
         * The retention clock is the last time anyone worked the basket, not
         * the day it was created — CartItem touches its cart. A buyer who
         * kept adding to a January basket in March must not lose it in April.
         */
        $cart = Cart::factory()->create();
        Product::factory()->create(['kode' => 'YH-CART-9', 'aktif' => true]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id, 'sku' => 'YH-CART-9']);
        Cart::query()->whereKey($cart->id)->update(['updated_at' => now()->subDays(120)]);

        $item->forceFill(['ordered_qty' => 9])->save();

        (new PruneAbandonedCarts)->handle();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }
}
