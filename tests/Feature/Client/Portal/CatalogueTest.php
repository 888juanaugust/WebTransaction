<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Models\CustomerPriceRule;
use App\Client\Models\PortalCartLine;
use App\Client\Portal\Filament\Resources\Catalogue\Pages\ListCatalogue;
use Livewire\Livewire;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The catalogue: every item on sale with the buyer's own price and an availability badge; adding to the cart from a row. */
class CatalogueTest extends TestCase
{
    use Buyer, OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 5);
    }

    public function test_each_buyer_sees_their_own_price_and_inactive_items_are_hidden(): void
    {
        $hidden = $this->sampleItem(['number' => 'ITM-OFF', 'name' => 'Off sale', 'is_active' => false]);
        $bare = $this->sampleItem(['number' => 'ITM-BARE', 'name' => 'Bare', 'sell_price' => 0]);
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 90_000, 'reason' => 'tender']);
        $other = $this->sampleCustomer(['name' => 'Other shop', 'number' => 'C-OTHER', 'branch_id' => $this->jakarta->id]);
        $otherBuyer = $this->buyer($other);

        $this->actingAsBuyer($this->buyer());
        $this->get('/portal/catalogue')->assertOk();
        Livewire::test(ListCatalogue::class)->assertOk()
            ->assertSee($this->item->name)->assertSee('Rp 90.000')->assertDontSee($hidden->name)
            ->assertSee($bare->name)->assertSee('Ask us')->assertSee('Available');

        auth('customer')->logout();
        $this->freshRequest();
        $this->actingAsBuyer($otherBuyer);
        Livewire::test(ListCatalogue::class)->assertSee('Rp 150.000')->assertDontSee('Rp 90.000');
    }

    public function test_a_row_adds_to_the_cart_in_the_chosen_unit(): void
    {
        $buyer = $this->actingAsBuyer($this->buyer());

        Livewire::test(ListCatalogue::class)
            ->callTableAction('add', $this->item, ['unit_id' => $this->item->unit1_id, 'quantity' => 4])
            ->assertHasNoTableActionErrors();

        $line = PortalCartLine::query()->sole();
        $this->assertSame($this->item->id, $line->item_id);
        $this->assertSame('4.0000', $line->quantity);
        $this->assertSame($buyer->id, $line->cart->customer_user_id);
    }
}
