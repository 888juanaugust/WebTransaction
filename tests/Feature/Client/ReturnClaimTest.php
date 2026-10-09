<?php

namespace Tests\Feature\Client;

use App\Client\Domain\Claims\ClaimStatus;
use App\Client\Domain\Claims\ReturnClaims;
use App\Client\Filament\Resources\ReturnClaims\Pages\CreateReturnClaim;
use App\Client\Filament\Resources\ReturnClaims\Pages\ViewReturnClaim;
use App\Client\Models\ReturnClaim;
use App\Domain\Inventory\StockQuery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Returns on two keys: the sales seat files which goods come back, Inventory verifies into a sales return; only then does stock move. */
class ReturnClaimTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 20);
    }

    private function claims(): ReturnClaims
    {
        return app(ReturnClaims::class);
    }

    private function lineOf(SalesInvoice $invoice): array
    {
        return ['sales_invoice_line_id' => $invoice->lines()->first()->id, 'quantity' => 2];
    }

    public function test_the_seat_files_and_inventory_verifies_into_a_posted_sales_return(): void
    {
        $invoice = $this->invoice(5, 100_000);
        $this->assertSame('15.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id));

        $this->actingAs($this->sales);
        $claim = $this->claims()->file($invoice, $this->gudangJakarta, [$this->lineOf($invoice)], 'Wrong type for the car', $this->sales);
        $this->assertSame(ClaimStatus::FILED, $claim->status);
        $this->assertSame('2.0000', $claim->lines()->first()->base_quantity);
        $this->assertSame('15.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id), 'filing moves nothing');

        $this->actingAs($this->inventory);
        $return = $this->claims()->verify($claim, $this->inventory, today()->toDateString());

        $this->assertInstanceOf(SalesReturn::class, $return);
        $this->assertSame($this->inventory->id, $return->created_by);
        $this->assertStringStartsWith('SR-JKT-', $return->number);
        $this->assertSame('sales_invoice', $return->source_type);
        $this->assertSame($invoice->id, $return->source_id);
        $this->assertSame('100000.0000', $return->lines()->first()->unit_price, 'the invoice line\'s price');
        $this->assertSame(222_000, $return->total, '2 × 100 000 plus VAT');
        $this->assertSame('17.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id), 'the goods are back');
        $this->assertSame(ClaimStatus::VERIFIED, $claim->fresh()->status);
        $this->assertSame($return->id, $claim->fresh()->sales_return_id);
    }

    public function test_a_line_never_returns_more_than_was_invoiced_less_what_came_back_already(): void
    {
        $invoice = $this->invoice(5, 100_000);
        $line = $invoice->lines()->first();
        $this->assertSame('5', $this->claims()->returnable($line)->toScale(0)->__toString());

        $first = $this->claims()->file($invoice, $this->gudangJakarta, [['sales_invoice_line_id' => $line->id, 'quantity' => 3]], 'damaged', $this->owner);
        $this->assertSame('2', $this->claims()->returnable($line->fresh())->toScale(0)->__toString(), 'a filed claim takes room');
        try {
            $this->claims()->file($invoice, $this->gudangJakarta, [['sales_invoice_line_id' => $line->id, 'quantity' => 3]], 'more', $this->owner);
            $this->fail('over the room');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('only 2.0000 more', $e->getMessage());
        }

        $this->actingAs($this->inventory);
        $this->claims()->verify($first, $this->inventory, today()->toDateString());
        $this->assertSame('2', $this->claims()->returnable($line->fresh())->toScale(0)->__toString(), 'a posted return takes room');
        $second = $this->claims()->file($invoice, $this->gudangJakarta, [['sales_invoice_line_id' => $line->id, 'quantity' => 2]], 'rest', $this->owner);
        $this->assertSame('0', $this->claims()->returnable($line->fresh())->toScale(0)->__toString());
        $this->assertNotNull($second);
    }

    public function test_who_files_and_who_verifies(): void
    {
        $invoice = $this->invoice(5, 100_000);
        try {
            $this->claims()->file($invoice, $this->gudangJakarta, [$this->lineOf($invoice)], 'x', $this->marketing);
            $this->fail('marketing filed a return');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sales seat', $e->getMessage());
        }
        try {
            $this->claims()->file($invoice, $this->gudangJakarta, [], 'x', $this->sales);
            $this->fail('no lines');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('at least one line', $e->getMessage());
        }

        $claim = $this->claims()->file($invoice, $this->gudangJakarta, [$this->lineOf($invoice)], 'x', $this->owner);
        try {
            $this->claims()->verify($claim, $this->owner, today()->toDateString());
            $this->fail('the owner verified their own claim');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('two keys, two people', $e->getMessage());
        }
        try {
            $this->claims()->verify($claim, $this->finance, today()->toDateString());
            $this->fail('finance posted a return');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Update right', $e->getMessage());
        }

        $this->actingAs($this->inventory);
        $this->claims()->reject($claim, $this->inventory, 'goods never arrived');
        $this->assertSame(ClaimStatus::REJECTED, $claim->fresh()->status);
        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertSame('15.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id));
    }

    public function test_the_screens_file_and_post(): void
    {
        $invoice = $this->invoice(5, 100_000);
        $line = $invoice->lines()->first();

        $this->actingAs($this->sales);
        $this->get('/admin/client/return-claims')->assertOk();
        Livewire::test(CreateReturnClaim::class)
            ->fillForm(['customer_id' => $this->customer->id, 'sales_invoice_id' => $invoice->id, 'warehouse_id' => $this->gudangJakarta->id, 'reason' => 'wrong type', 'lines' => [['sales_invoice_line_id' => $line->id, 'quantity' => 1]]])
            ->call('create')->assertHasNoFormErrors();
        $claim = ReturnClaim::query()->sole();
        $this->assertSame(1, $claim->lines()->count());

        $this->actingAs($this->inventory);
        Livewire::test(ViewReturnClaim::class, ['record' => $claim->getRouteKey()])
            ->assertOk()->assertSee($this->item->name)->assertActionVisible('verify')
            ->callAction('verify', ['trans_date' => today()->toDateString()])
            ->assertHasNoActionErrors();
        $this->assertSame(ClaimStatus::VERIFIED, $claim->fresh()->status);
        $this->assertSame('16.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id));
    }
}
