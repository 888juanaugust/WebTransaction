<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Claims\ReturnClaims;
use App\Client\Domain\Stock\DamagedGoods;
use App\Client\Domain\Stock\Reservations;
use App\Client\Filament\Pages\DamagedGoods as DamagedGoodsPage;
use App\Client\Seeders\ScrapWarehouseSeeder;
use App\Domain\Inventory\StockQuery;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Damaged returns go to the branch's damaged-goods warehouse, never count as available, and are written off from Damaged Goods. */
class DamagedReturnTest extends TestCase
{
    use OrderFlow;

    private Warehouse $rusak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 10, date: '2026-05-01');
        (new ScrapWarehouseSeeder)->run(); // the fixture's branches came after the seed
        $this->rusak = Warehouse::query()->where('scrap_warehouse', true)->where('branch_id', $this->jakarta->id)->firstOrFail();
    }

    private function line(SalesInvoice $invoice, int $qty, string $condition): array
    {
        return ['sales_invoice_line_id' => $invoice->lines()->first()->id, 'quantity' => $qty, 'condition' => $condition];
    }

    public function test_the_seeder_gives_every_branch_a_damaged_goods_warehouse_and_the_loss_account_exists(): void
    {
        $this->assertStringStartsWith('Gudang Rusak', $this->rusak->name);
        $this->assertNotNull(Warehouse::query()->where('scrap_warehouse', true)->where('branch_id', $this->surabaya->id)->first());
        $this->assertSame('6600', DamagedGoods::lossAccount()->no);
        $this->assertSame($this->rusak->id, DamagedGoods::warehouseFor($this->jakarta->id)->id);
    }

    public function test_a_damaged_line_lands_in_the_damaged_goods_warehouse_and_a_good_one_in_the_chosen_one(): void
    {
        $this->actingAs($this->owner);
        $invoice = $this->invoice(6, 100_000);
        $this->actingAs($this->sales);
        $claim = app(ReturnClaims::class)->file($invoice, $this->gudangJakarta, [$this->line($invoice, 4, DamagedGoods::GOOD)], 'Two of them cracked', $this->sales);
        $line = $claim->lines()->first();
        $this->assertSame(DamagedGoods::GOOD, $line->condition);

        $this->actingAs($this->inventory);
        $return = app(ReturnClaims::class)->verify($claim, $this->inventory, today()->toDateString(), null, [$line->id => DamagedGoods::DAMAGED]);

        $this->assertSame(DamagedGoods::DAMAGED, $line->fresh()->condition, 'the verifier had the last word');
        $this->assertSame($this->rusak->id, $return->lines()->first()->warehouse_id);
        $this->assertSame('4.0000', StockQuery::onHand($this->item->id, $this->rusak->id));
        $this->assertSame('4.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id), '10 in, 6 sold, nothing came back here');
        $this->assertSame(444_000, $return->total, 'credited in full');
        $this->assertSame('4.0000', app(Reservations::class)->availableAnywhere($this->item->id), 'damaged goods are not for sale');
    }

    public function test_verifying_a_damaged_line_refuses_without_a_damaged_goods_warehouse(): void
    {
        Warehouse::query()->where('scrap_warehouse', true)->update(['is_active' => false]);
        $this->actingAs($this->owner);
        $invoice = $this->invoice(2, 100_000);
        $claim = app(ReturnClaims::class)->file($invoice, $this->gudangJakarta, [$this->line($invoice, 1, DamagedGoods::DAMAGED)], 'Broken', $this->owner);

        $this->actingAs($this->inventory);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('damaged-goods warehouse');
        app(ReturnClaims::class)->verify($claim, $this->inventory, today()->toDateString());
    }

    public function test_damaged_goods_lists_what_sits_there_and_writes_it_off_on_the_loss_account(): void
    {
        $this->actingAs($this->owner);
        $invoice = $this->invoice(3, 100_000);
        $claim = app(ReturnClaims::class)->file($invoice, $this->gudangJakarta, [$this->line($invoice, 3, DamagedGoods::DAMAGED)], 'All dented', $this->owner);
        $this->actingAs($this->inventory);
        $return = app(ReturnClaims::class)->verify($claim, $this->inventory, today()->toDateString());

        $rows = app(DamagedGoods::class)->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('3.0000', $rows[0]['on_hand']);
        $this->assertSame($return->number, $rows[0]['return']);
        $this->assertSame(300_000, $rows[0]['value']);

        Livewire::test(DamagedGoodsPage::class)->assertOk()->assertSee($this->item->number)
            ->callTableAction('write_off', $rows[0]['key'], ['quantity' => 2, 'trans_date' => today()->toDateString(), 'account_id' => DamagedGoods::lossAccount()->id, 'memo' => 'beyond repair'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('1.0000', StockQuery::onHand($this->item->id, $this->rusak->id));
        $adjustment = InventoryAdjustment::query()->where('description', 'like', 'Damaged goods written off%')->sole();
        $this->assertSame($this->inventory->id, $adjustment->created_by);
        $this->assertSame(DamagedGoods::lossAccount()->id, $adjustment->lines()->first()->adjustment_account_id);
        $this->assertSame(100_000, (int) \App\Models\Inventory\ItemCost::query()->where('item_id', $this->item->id)->where('warehouse_id', $this->rusak->id)->value('total_value'), 'two left at the average cost');
        $this->assertTrue(Account::query()->where('no', '6600')->exists());
    }
}
