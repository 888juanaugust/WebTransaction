<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Models\CustomerUser;
use App\Client\Portal\Filament\Pages\Home;
use App\Client\Portal\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Client\Portal\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Client\Portal\Filament\Resources\Orders\Pages\ListOrders;
use App\Client\Portal\Filament\Resources\Orders\Pages\ViewOrder;
use App\Client\Portal\Filament\Widgets\ReorderLastOrder;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Format;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use Livewire\Livewire;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The buyer's screens: the home with its widgets and the credit strip, orders and invoices scoped to their customer, the aging banner. */
class PortalScreensTest extends TestCase
{
    use Buyer, OrderFlow;

    private CustomerUser $buyer;

    private SalesOrder $order;

    private SalesInvoice $invoice;

    private SalesOrder $foreignOrder;

    private SalesInvoice $foreignInvoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
        app(Preferensi::class)->set(PreferensiKey::CreditNoticeDays, 120);
        app(Preferensi::class)->set(PreferensiKey::CreditFreezeDays, 150);
        $this->customer->forceFill(['credit_limit_amount_enabled' => true, 'credit_limit_amount' => 5_000_000])->saveQuietly();

        $this->order = $this->order(5, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($this->order, $this->marketing);
        $this->deliver($this->order, 2);
        $this->invoice = $this->invoice(1, 100_000, date: today()->subDays(40)->toDateString());

        $other = $this->sampleCustomer(['name' => 'Other shop', 'number' => 'C-OTHER', 'branch_id' => $this->jakarta->id, 'default_warehouse_id' => $this->gudangJakarta->id]);
        $mine = $this->customer;
        $this->customer = $other;
        $this->foreignOrder = $this->order(1, $this->gudangJakarta);
        $this->foreignInvoice = $this->invoice(1, 100_000);
        $this->customer = $mine;

        $this->buyer = $this->actingAsBuyer($this->buyer());
    }

    public function test_the_home_shows_the_credit_strip_the_last_order_and_the_open_invoice(): void
    {
        $this->get('/portal')->assertOk()
            ->assertSee('Free credit')->assertSee('You owe')->assertSee('On order')
            ->assertSee($this->order->number)->assertSee($this->invoice->number)
            ->assertSee('Order your last order again')->assertDontSee($this->foreignOrder->number)->assertDontSee($this->foreignInvoice->number);
        $this->assertSame(111_000, (int) Format::money(0) === '' ? 0 : $this->invoice->balance());

        Livewire::test(ReorderLastOrder::class)->assertSee($this->item->name)
            ->set('quantities', [$this->order->lines()->first()->id => 3])
            ->call('placeAgain');
        $again = SalesOrder::query()->where('placed_by_customer_user_id', $this->buyer->id)->sole();
        $this->assertSame('3.0000', $again->lines()->first()->quantity);
    }

    public function test_orders_and_invoices_are_the_buyers_own_and_a_guessed_id_is_not_found(): void
    {
        Livewire::test(ListOrders::class)->assertOk()->assertSee($this->order->number)->assertDontSee($this->foreignOrder->number);
        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])->assertOk()
            ->assertSee('Partly delivered')->assertSee('DO-')->assertSee('Surat jalan PDF')->assertSee('Not invoiced yet')->assertActionVisible('pdf');
        $this->get('/portal/orders/'.$this->foreignOrder->getRouteKey())->assertNotFound();

        Livewire::test(ListInvoices::class)->assertOk()->assertSee($this->invoice->number)->assertDontSee($this->foreignInvoice->number)->assertSee('days late');
        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])->assertOk()->assertSee('Rp 111.000');
        $this->get('/portal/invoices/'.$this->foreignInvoice->getRouteKey())->assertNotFound();
    }

    public function test_the_aging_banner_tells_the_notice_then_the_freeze(): void
    {
        $this->get('/portal')->assertOk()->assertDontSee('ae-aging-banner', false);

        $this->travel(85)->days();
        $this->get('/portal')->assertOk()->assertSee('ae-aging-banner', false)->assertSee('From ')->assertSee('new orders are frozen');

        $this->travel(30)->days();
        $this->get('/portal')->assertOk()->assertSee('ae-aging-banner--frozen', false)->assertSee('Your account is frozen');
        $this->get('/portal/cart')->assertOk()->assertSee('New orders are frozen');
        $this->travelBack();
    }
}
