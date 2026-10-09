<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Domain\Pricing\PriceReason;
use App\Client\Models\CustomerPriceRule;
use App\Client\Models\CustomerUser;
use App\Client\Models\PortalCart;
use App\Client\Models\PortalCartLine;
use App\Client\Portal\Domain\Cart;
use App\Client\Portal\Domain\CartEstimate;
use App\Client\Portal\Filament\Pages\CartPage;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The cart: lines in the item's units, merged, owned; the estimate from the customer's rules, with availability and credit. */
class CartTest extends TestCase
{
    use Buyer, OrderFlow;

    private CustomerUser $buyer;

    private Unit $ctn;

    private Item $bare;

    private CustomerPriceRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 30);
        $this->ctn = Unit::query()->firstOrCreate(['name' => 'CTN']);
        $this->item->units()->create(['sort' => 1, 'unit_id' => $this->ctn->id, 'ratio' => 12]);
        $this->bare = $this->sampleItem(['number' => 'ITM-BARE', 'name' => 'Bare', 'sell_price' => 0]);
        $this->customer->forceFill(['default_inc_tax' => false])->saveQuietly();
        $this->rule = CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 90_000, 'reason' => 'tender', 'is_active' => false]);
        $this->buyer = $this->actingAsBuyer($this->buyer());
    }

    private function cart(): Cart
    {
        return app(Cart::class);
    }

    public function test_lines_merge_on_item_and_unit_and_base_quantities_are_derived(): void
    {
        $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 5);
        $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 3);
        $this->cart()->add($this->buyer, $this->item, $this->ctn->id, 2);

        $cart = PortalCart::query()->sole();
        $this->assertSame($this->customer->id, $cart->customer_id);
        $lines = $cart->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines, 'PCS merged, CTN its own line');
        $this->assertSame('8.0000', $lines[0]->quantity);
        $this->assertSame('2.0000', $lines[1]->quantity);
        $this->assertSame(2, $this->cart()->count($this->buyer));

        $estimate = app(CartEstimate::class)->of($cart);
        $this->assertSame('8.0000', $estimate['lines'][0]['base_quantity']);
        $this->assertSame('24.0000', $estimate['lines'][1]['base_quantity'], '2 cartons of 12');
        $this->assertSame([(int) $this->item->unit1_id => 'PCS', $this->ctn->id => 'CTN'], Cart::unitsOf($this->item));
    }

    public function test_a_wrong_unit_an_inactive_item_and_a_zero_quantity_are_refused(): void
    {
        $other = Unit::query()->firstOrCreate(['name' => 'BOX']);
        try {
            $this->cart()->add($this->buyer, $this->item, $other->id, 1);
            $this->fail('unit not the item\'s');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not sold in that unit', $e->getMessage());
        }
        try {
            $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 0);
            $this->fail('zero');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('above zero', $e->getMessage());
        }
        $this->item->forceFill(['is_active' => false])->saveQuietly();
        try {
            $this->cart()->add($this->buyer, $this->item->fresh(), $this->item->unit1_id, 1);
            $this->fail('inactive');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not on sale', $e->getMessage());
        }
        $this->assertSame(0, PortalCartLine::query()->count());
    }

    public function test_a_line_belongs_to_its_buyer_and_zero_removes_it(): void
    {
        $line = $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 5);
        $stranger = $this->buyer(attributes: ['email' => 'other@example.test']);
        try {
            $this->cart()->setQuantity($stranger, $line, 1);
            $this->fail('another buyer\'s line');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not in your cart', $e->getMessage());
        }
        $this->cart()->setQuantity($this->buyer, $line, 7);
        $this->assertSame('7.0000', $line->fresh()->quantity);
        $this->cart()->setQuantity($this->buyer, $line, 0);
        $this->assertNull($line->fresh());

        $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 1);
        $this->cart()->clear($this->buyer);
        $this->assertSame(0, $this->cart()->count($this->buyer));
    }

    public function test_the_estimate_prices_from_the_customers_rules_and_reads_the_home_warehouse(): void
    {
        $this->rule->forceFill(['is_active' => true])->saveQuietly();
        $this->customer->forceFill(['credit_limit_amount_enabled' => true, 'credit_limit_amount' => 1_000_000])->saveQuietly();
        $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 10);
        $this->cart()->add($this->buyer, $this->item, $this->ctn->id, 3);

        $estimate = app(CartEstimate::class)->of($this->cart()->forBuyer($this->buyer));
        [$pcs, $ctn] = $estimate['lines'];
        $this->assertSame(PriceReason::CustomerPrice->value, $pcs['reason']);
        $this->assertSame('90000.0000', $pcs['unit_price']);
        $this->assertSame('1080000.0000', $ctn['unit_price'], 'the carton price is 12 times the piece price');
        $this->assertSame(900_000, $pcs['amount']);
        $this->assertSame(3_240_000, $ctn['amount']);
        $this->assertSame(4_140_000, $estimate['subtotal']);
        $this->assertSame(455_400, $estimate['tax_total'], '12 % VAT on an 11/12 base');
        $this->assertSame(4_595_400, $estimate['total']);
        $this->assertSame(CartEstimate::AVAILABLE, $pcs['availability'], '10 of 30 on the shelf');
        $this->assertSame(CartEstimate::LIMITED, $ctn['availability'], '36 asked, 30 on the shelf');
        $this->assertSame(1_000_000, $estimate['free_credit']);
        $this->assertTrue($estimate['over_credit']);
        $this->assertFalse($estimate['frozen']);
        $this->assertSame(0, $estimate['unpriced']);
    }

    public function test_an_unpriced_item_is_flagged_and_an_empty_shelf_says_ask_us(): void
    {
        $bare = $this->bare;
        $this->cart()->add($this->buyer, $bare, $bare->unit1_id, 1);

        $estimate = app(CartEstimate::class)->of($this->cart()->forBuyer($this->buyer));
        $this->assertTrue($estimate['lines'][0]['unpriced']);
        $this->assertSame(0, $estimate['lines'][0]['amount']);
        $this->assertSame(1, $estimate['unpriced']);
        $this->assertSame(CartEstimate::ASK, $estimate['lines'][0]['availability']);
        $this->assertNull($estimate['free_credit'], 'no amount limit set');
    }

    public function test_the_cart_page_lists_prices_and_lets_the_buyer_change_or_remove_lines(): void
    {
        $line = $this->cart()->add($this->buyer, $this->item, $this->item->unit1_id, 5);

        $this->get('/portal/cart')->assertOk()->assertSee('Rp 150.000');
        Livewire::test(CartPage::class)->assertOk()->assertSee($this->item->name)
            ->callTableColumnAction('quantity', $line);
        Livewire::test(CartPage::class)->callTableAction('remove', $line);
        $this->assertNull($line->fresh());
        Livewire::test(CartPage::class)->assertSee('Your cart is empty');
    }
}
