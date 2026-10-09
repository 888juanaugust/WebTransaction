<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Models\CustomerUser;
use App\Client\Models\PortalCart;
use App\Client\Portal\Domain\BuyerOrderPlacer;
use App\Models\Sales\SalesOrder;
use RuntimeException;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Reorder: a past order again with the quantities the buyer types, straight to an order awaiting approval; old carts are pruned. */
class ReorderTest extends TestCase
{
    use Buyer, OrderFlow;

    private CustomerUser $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50);
        $this->buyer = $this->buyer();
    }

    public function test_a_past_order_is_placed_again_with_edited_quantities_and_dropped_lines(): void
    {
        $previous = $this->order(5, $this->gudangJakarta, price: 150_000);
        $second = $this->sampleItem(['number' => 'ITM-2', 'name' => 'Gasket']);
        $previous->lines()->create(['sort' => 1, 'item_id' => $second->id, 'quantity' => 2, 'unit_id' => $second->unit1_id, 'base_quantity' => 2, 'unit_price' => 150_000, 'tax_code_id' => $this->vat->id]);
        $previous->forceFill(['po_number' => 'PO-OLD'])->save();
        [$first, $gasket] = $previous->lines()->orderBy('sort')->get();
        $this->actingAsBuyer($this->buyer);

        $again = app(BuyerOrderPlacer::class)->repeat($this->buyer, $previous, [$first->id => 8, $gasket->id => 0]);

        $this->assertNotSame($previous->id, $again->id);
        $this->assertSame(SalesOrder::AWAITING, $again->approval_status);
        $this->assertSame($this->buyer->id, $again->placed_by_customer_user_id);
        $this->assertSame('PO-OLD', $again->po_number);
        $this->assertStringContainsString($previous->number, (string) $again->description);
        $lines = $again->lines()->get();
        $this->assertCount(1, $lines, 'the zeroed line is dropped');
        $this->assertSame('8.0000', $lines[0]->quantity);
        $this->assertSame('150000.0000', $lines[0]->unit_price, 'priced again today, not copied');
    }

    public function test_another_customers_order_and_an_all_zero_reorder_are_refused(): void
    {
        $previous = $this->order(5, $this->gudangJakarta);
        $other = $this->sampleCustomer(['name' => 'Other', 'number' => 'C-O', 'branch_id' => $this->jakarta->id]);
        $stranger = $this->buyer($other);
        $this->actingAsBuyer($stranger);
        try {
            app(BuyerOrderPlacer::class)->repeat($stranger, $previous);
            $this->fail('not theirs');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not yours', $e->getMessage());
        }

        auth('customer')->logout();
        $this->freshRequest();
        $this->actingAsBuyer($this->buyer);
        try {
            app(BuyerOrderPlacer::class)->repeat($this->buyer, $previous, [$previous->lines()->first()->id => 0]);
            $this->fail('nothing left');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('at least one line', $e->getMessage());
        }
        $this->assertSame(1, SalesOrder::query()->count());
    }

    public function test_carts_untouched_for_the_retention_period_are_pruned(): void
    {
        $old = PortalCart::query()->create(['customer_user_id' => $this->buyer->id, 'customer_id' => $this->customer->id]);
        PortalCart::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(91)]);
        $fresh = PortalCart::query()->create(['customer_user_id' => $this->buyer(attributes: ['email' => 'b2@example.test'])->id, 'customer_id' => $this->customer->id]);

        $this->artisan('central:prune-carts')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($fresh->fresh());
    }
}
