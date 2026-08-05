<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\BuyerOrderPlacer;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Uom\Unit;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reorder — the buyer portal's first priority and, by the spec's estimate, 80%
 * of what the portal is for.
 *
 * The thing worth guarding is not the happy path but the boundary: a buyer
 * proposes, staff dispose. A reorder must stop at `submitted`, must not price
 * itself, and must not hold stock — because pricing, credit and stock all
 * belong to `confirmed`, which is a staff action.
 */
class BuyerReorderTest extends TestCase
{
    use RefreshDatabase;

    private const SKU_A = 'YH-A1';

    private const SKU_B = 'OS-B2';

    private Company $company;

    private CustomerUser $buyer;

    private Warehouse $warehouse;

    private Order $previous;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->creditLimit(500_000_000)->create();
        $this->buyer = CustomerUser::factory()->create(['company_id' => $this->company->id]);
        $this->warehouse = Warehouse::factory()->create();

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        foreach ([self::SKU_A => 150_000, self::SKU_B => 90_000] as $kode => $harga) {
            Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => 12, 'satuan_dasar' => 'PCS']);
            PriceListItem::factory()->create([
                'version_id' => $version->id, 'kode' => $kode, 'harga' => $harga,
            ]);

            app(StockLedger::class)->record($kode, $this->warehouse->id, 600, MovementReason::Penerimaan);
        }

        $this->previous = $this->pastOrder();
    }

    /** A completed order to repeat: 20 PCS of A, 2 cartons of B. */
    private function pastOrder(): Order
    {
        $order = Order::factory()->create([
            'nomor' => 'SO-PAST-1',
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        OrderLine::factory()->qty(20)->create([
            'order_id' => $order->id, 'sku' => self::SKU_A, 'urutan' => 1,
        ]);

        OrderLine::factory()->cartons(2, 12)->create([
            'order_id' => $order->id, 'sku' => self::SKU_B, 'urutan' => 2,
        ]);

        return $order->refresh();
    }

    private function placer(): BuyerOrderPlacer
    {
        return app(BuyerOrderPlacer::class);
    }

    // --- the happy path -----------------------------------------------------

    public function test_repeating_an_order_copies_its_lines(): void
    {
        $new = $this->placer()->repeat($this->buyer, $this->previous, []);

        $this->assertSame(2, $new->lines()->count());

        $lines = $new->lines()->orderBy('urutan')->get();

        $this->assertSame(self::SKU_A, $lines[0]->sku);
        $this->assertSame(20, $lines[0]->ordered_qty);
        $this->assertSame(Unit::Pcs, $lines[0]->ordered_unit);

        // The carton line stays a carton line. Flattening it to pieces would
        // change what the buyer sees on their own order.
        $this->assertSame(self::SKU_B, $lines[1]->sku);
        $this->assertSame(2, $lines[1]->ordered_qty);
        $this->assertSame(Unit::Ctn, $lines[1]->ordered_unit);
        $this->assertSame(24, $lines[1]->qty_base);
    }

    public function test_quantities_can_be_edited(): void
    {
        $first = $this->previous->lines()->orderBy('urutan')->first();

        $new = $this->placer()->repeat($this->buyer, $this->previous, [$first->id => 35]);

        $this->assertSame(35, $new->lines()->where('sku', self::SKU_A)->value('ordered_qty'));
        // Untouched lines keep last time's quantity.
        $this->assertSame(2, $new->lines()->where('sku', self::SKU_B)->value('ordered_qty'));
    }

    public function test_setting_a_quantity_to_zero_drops_the_line(): void
    {
        $first = $this->previous->lines()->orderBy('urutan')->first();

        $new = $this->placer()->repeat($this->buyer, $this->previous, [$first->id => 0]);

        $this->assertSame(1, $new->lines()->count());
        $this->assertSame(self::SKU_B, $new->lines()->value('sku'));
    }

    public function test_dropping_every_line_is_refused(): void
    {
        $quantities = $this->previous->lines->mapWithKeys(fn ($l) => [$l->id => 0])->all();

        $this->expectException(DomainException::class);

        $this->placer()->repeat($this->buyer, $this->previous, $quantities);
    }

    public function test_a_reorder_gets_its_own_document_number(): void
    {
        $new = $this->placer()->repeat($this->buyer, $this->previous, []);

        $this->assertNotSame($this->previous->nomor, $new->nomor);
        $this->assertMatchesRegularExpression('/^SO-\d{6}-\d{4}$/', $new->nomor);
    }

    // --- the boundary: a buyer proposes, staff dispose ----------------------

    /**
     * The whole point. A buyer-placed order stops at `submitted` — it is not
     * priced, holds no stock, and has not been checked against a credit limit,
     * because all three of those happen at `confirmed` and `confirmed` is a
     * staff action.
     */
    public function test_a_reorder_stops_at_submitted_and_commits_nothing(): void
    {
        $new = $this->placer()->repeat($this->buyer, $this->previous, []);

        $this->assertSame(OrderStatus::Submitted, $new->status);
        $this->assertNull($new->confirmed_at);
        $this->assertSame(0, $new->total_rupiah, 'a buyer-placed order must not price itself');
        $this->assertNull($new->price_list_version_id);
        $this->assertSame(0, $new->reservations()->count(), 'a buyer must not be able to hold stock');

        foreach ($new->lines as $line) {
            $this->assertFalse($line->isPriced(), 'no line may carry a price snapshot yet');
        }
    }

    public function test_the_buyer_is_recorded_as_the_placer_and_no_staff_user_is_invented(): void
    {
        $new = $this->placer()->repeat($this->buyer, $this->previous, []);

        $this->assertSame($this->buyer->id, $new->placed_by_customer_user_id);
        $this->assertTrue($new->placedInPortal());
        $this->assertNull($new->created_by, 'no staff member placed this order');

        $event = $new->events()->where('to_status', OrderStatus::Submitted->value)->firstOrFail();

        $this->assertSame($this->buyer->id, $event->customer_actor_id);
        $this->assertNull($event->actor_id, 'a buyer is not a staff actor');
        $this->assertSame($this->buyer->name, $event->actorLabel());
    }

    /** Staff confirmation still works on a portal-placed order, and prices it. */
    public function test_staff_can_confirm_a_buyer_placed_order_normally(): void
    {
        $new = $this->placer()->repeat($this->buyer, $this->previous, []);

        app(OrderStateMachine::class)->confirm($new, User::factory()->sales()->create());

        $new->refresh();

        $this->assertSame(OrderStatus::Confirmed, $new->status);
        // 20 × 150.000 + 24 × 90.000
        $this->assertSame(5_160_000, $new->subtotal_rupiah);
        $this->assertSame(2, $new->reservations()->count());
    }

    // --- refusals -----------------------------------------------------------

    public function test_a_buyer_cannot_repeat_another_companys_order(): void
    {
        $theirs = Order::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
        ]);
        OrderLine::factory()->qty(5)->create(['order_id' => $theirs->id, 'sku' => self::SKU_A]);

        $this->expectException(DomainException::class);

        $this->placer()->repeat($this->buyer, $theirs->refresh(), []);
    }

    /**
     * A session outliving a suspension must not keep ordering on credit.
     * Login already refuses a suspended company; this is the second lock.
     */
    public function test_a_suspended_company_cannot_place_an_order(): void
    {
        $this->company->forceFill(['status' => Company::STATUS_SUSPENDED])->save();

        $this->expectException(DomainException::class);

        $this->placer()->repeat($this->buyer->refresh(), $this->previous, []);
    }

    public function test_an_inactive_product_cannot_be_reordered(): void
    {
        Product::query()->where('kode', self::SKU_A)->update(['aktif' => false]);

        $this->expectException(DomainException::class);

        $this->placer()->repeat($this->buyer, $this->previous, []);
    }

    /**
     * qty_base is derived from the product, never carried over from the old
     * line — otherwise changing a carton size would leave every reorder
     * shipping the old quantity while the screen showed the new one.
     */
    public function test_base_quantity_is_recomputed_from_the_product_not_copied(): void
    {
        Product::query()->where('kode', self::SKU_B)->update(['qty_per_ctn' => 20]);

        $new = $this->placer()->repeat($this->buyer, $this->previous, []);

        $line = $new->lines()->where('sku', self::SKU_B)->firstOrFail();

        $this->assertSame(20, $line->qty_per_ctn_snapshot);
        $this->assertSame(40, $line->qty_base, '2 cartons of 20, not the old 24');
    }
}
