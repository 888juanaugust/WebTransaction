<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Domain\Pricing\PriceReason;
use App\Client\Models\CustomerPriceRule;
use App\Client\Models\CustomerUser;
use App\Client\Portal\Domain\BuyerOrderPlacer;
use App\Client\Portal\Domain\Cart;
use App\Client\Portal\Filament\Pages\CartPage;
use App\Client\Portal\PortalActor;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\AuditLog;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Sales\SalesOrder;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Checkout: the cart becomes an order awaiting approval in the Portal user's name, priced on the server; the freeze and an unpriced line refuse. */
class CheckoutTest extends TestCase
{
    use Buyer, OrderFlow;

    private CustomerUser $buyer;

    private Unit $ctn;

    private Item $bare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
        $this->ctn = Unit::query()->firstOrCreate(['name' => 'CTN']);
        $this->item->units()->create(['sort' => 1, 'unit_id' => $this->ctn->id, 'ratio' => 12]);
        $this->customer->forceFill(['default_inc_tax' => false])->saveQuietly();
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 90_000, 'reason' => 'tender']);
        app(Preferensi::class)->set(PreferensiKey::CreditFreezeDays, 150);
        $this->bare = Item::query()->create(['number' => 'ITM-BARE', 'name' => 'Bare', 'unit1_id' => $this->item->unit1_id, 'sell_price' => 0, 'purchase_price' => 0, 'is_active' => true]);
        $this->buyer = $this->actingAsBuyer($this->buyer());
    }

    public function test_the_cart_becomes_an_order_awaiting_approval_written_by_the_portal_user_with_the_buyer_on_it(): void
    {
        $cart = app(Cart::class);
        $cart->add($this->buyer, $this->item, $this->item->unit1_id, 10);
        $cart->add($this->buyer, $this->item, $this->ctn->id, 2);
        $cart->forBuyer($this->buyer)->forceFill(['po_number' => 'PO-77', 'note' => 'Deliver in the morning'])->save();

        $order = app(BuyerOrderPlacer::class)->checkout($this->buyer, $cart);

        $this->assertSame(SalesOrder::AWAITING, $order->approval_status);
        $this->assertSame(PortalActor::user()->id, $order->created_by, 'written in the Portal user\'s name');
        $this->assertSame($this->buyer->id, $order->placed_by_customer_user_id);
        $this->assertStringStartsWith('SO-JKT-', $order->number);
        $this->assertSame('PO-77', $order->po_number);
        $this->assertSame('Deliver in the morning', $order->description);
        $this->assertSame($this->customer->branch_id, $order->branch_id);
        $lines = $order->lines()->orderBy('sort')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('10.0000', $lines[0]->base_quantity);
        $this->assertSame('90000.0000', $lines[0]->unit_price, 'the customer\'s rule, on the server');
        $this->assertSame('24.0000', $lines[1]->base_quantity, '2 cartons of 12');
        $this->assertSame('1080000.0000', $lines[1]->unit_price);
        $this->assertSame($this->gudangJakarta->id, $lines[0]->warehouse_id, 'the home warehouse');
        $this->assertSame(3_060_000, $order->subtotal);
        $this->assertSame('awaiting', app(ApprovalEngine::class)->status($order));
        $this->assertSame(0, $cart->count($this->buyer), 'the cart is emptied');
        $this->assertNull($cart->forBuyer($this->buyer)->po_number);
        $this->assertContains('portal_order_placed', AuditLog::query()->where('document_type', 'sales_order')->pluck('action')->all());
        $this->assertSame(PortalActor::user()->id, AuditLog::query()->where('action', 'portal_order_placed')->value('user_id'));
        $this->assertSame('customer', auth()->getDefaultDriver(), 'the guard is restored');
        $this->assertTrue(auth('customer')->check());
        $this->assertFalse(auth('web')->user()?->is(PortalActor::user()) ?? false, 'the Portal user is taken off the web guard');

        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->assertSame(PriceReason::CustomerPrice->value, $order->fresh()->lines()->first()->price_reason, 'the stamp at approval agrees');
    }

    public function test_an_empty_cart_a_frozen_account_and_an_unpriced_line_refuse(): void
    {
        $cart = app(Cart::class);
        try {
            app(BuyerOrderPlacer::class)->checkout($this->buyer, $cart);
            $this->fail('empty');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $bare = $this->bare;
        $cart->add($this->buyer, $bare, $bare->unit1_id, 1);
        try {
            app(BuyerOrderPlacer::class)->checkout($this->buyer, $cart);
            $this->fail('unpriced');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no price yet', $e->getMessage());
        }
        $this->assertSame(1, $cart->count($this->buyer), 'the cart survives a refusal');
        $cart->clear($this->buyer);

        $this->agedInvoice();
        $cart->add($this->buyer, $this->item, $this->item->unit1_id, 1);
        try {
            app(BuyerOrderPlacer::class)->checkout($this->buyer, $cart);
            $this->fail('frozen');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }
        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_the_cart_page_places_the_order(): void
    {
        app(Cart::class)->add($this->buyer, $this->item, $this->item->unit1_id, 3);

        Livewire::test(CartPage::class)->assertActionVisible('checkout')
            ->callAction('checkout', ['po_number' => 'PO-1', 'note' => '', 'terms' => true])
            ->assertHasNoActionErrors();

        $order = SalesOrder::query()->sole();
        $this->assertSame('PO-1', $order->po_number);
        $this->assertSame($this->buyer->id, $order->placed_by_customer_user_id);
        $this->assertSame(0, app(Cart::class)->count($this->buyer));
    }

    public function test_a_double_checkout_makes_one_order(): void
    {
        $cart = app(Cart::class);
        $cart->add($this->buyer, $this->item, $this->item->unit1_id, 1);
        app(BuyerOrderPlacer::class)->checkout($this->buyer, $cart);
        try {
            app(BuyerOrderPlacer::class)->checkout($this->buyer, $cart);
        } catch (RuntimeException) {
        }
        $this->assertSame(1, SalesOrder::query()->count());
    }

    private function bareItem(): Item
    {
        return Item::query()->create(['number' => 'ITM-BARE', 'name' => 'Bare', 'unit1_id' => $this->item->unit1_id, 'sell_price' => 0, 'purchase_price' => 0, 'is_active' => true]);
    }

    /** An unpaid invoice 160 days old, made as the owner. */
    private function agedInvoice(): void
    {
        $this->actingAsStaff($this->owner);
        $this->invoice(1, 100_000, date: today()->subDays(160)->toDateString());
        $this->freshRequest();
        $this->actingAsBuyer($this->buyer);
    }
}
