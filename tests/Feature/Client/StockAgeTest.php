<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Stock\ProductAnalytics;
use App\Client\Domain\Stock\StockAge;
use App\Client\Filament\Pages\ProductAnalytics as AnalyticsPage;
use App\Client\Filament\Pages\StockAge as StockAgePage;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Reports\Period;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Stock age from the ledger's layers, and the product analytics over the same ledgers. */
class StockAgeTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
    }

    public function test_issues_take_the_oldest_layer_first_and_the_rest_keeps_its_date(): void
    {
        $this->stock($this->gudangJakarta, 10, date: today()->subDays(100)->toDateString());
        $this->stock($this->gudangJakarta, 5, date: today()->subDays(20)->toDateString());
        $this->actingAs($this->owner);
        $order = $this->order(8);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->deliver($order, 8, $this->gudangJakarta, today()->toDateString());

        $layers = app(StockAge::class)->layers($this->item->id, $this->gudangJakarta->id);

        $this->assertSame([['date' => today()->subDays(100)->toDateString(), 'quantity' => '2.0000', 'days' => 100], ['date' => today()->subDays(20)->toDateString(), 'quantity' => '5.0000', 'days' => 20]], $layers);

        $rows = app(StockAge::class)->rows();
        $row = $rows->firstWhere('item_id', $this->item->id);
        $this->assertSame('7.0000', $row['on_hand']);
        $this->assertSame(100, $row['oldest_days']);
        $this->assertSame('91-180', $row['bucket']);
        $this->assertSame('2.0000', $row['buckets']['91-180']);
        $this->assertSame('5.0000', $row['buckets']['0-30']);
        $this->assertSame(700_000, $row['value'], 'at the average cost');
    }

    public function test_buckets_follow_the_edges(): void
    {
        $this->assertSame('0-30', StockAge::bucket(0));
        $this->assertSame('0-30', StockAge::bucket(30));
        $this->assertSame('31-90', StockAge::bucket(31));
        $this->assertSame('181-365', StockAge::bucket(365));
        $this->assertSame('over-365', StockAge::bucket(366));
    }

    public function test_the_analytics_rank_what_sold_what_sits_idle_and_what_never_sold(): void
    {
        $this->stock($this->gudangJakarta, 50, date: today()->subDays(200)->toDateString());
        $other = $this->sampleItem(['number' => 'ITM-IDLE', 'name' => 'Idle part']);
        $this->stock($this->gudangJakarta, 3, $other, today()->subDays(200)->toDateString());
        $this->actingAs($this->owner);
        $this->invoice(4, 100_000, date: today()->subDays(2)->toDateString());
        $this->invoice(1, 100_000, date: today()->subDays(1)->toDateString());
        $period = new Period(today()->subDays(30)->toImmutable(), today()->toImmutable());

        $sold = app(ProductAnalytics::class)->rows(['view' => 'most_sold', 'period' => $period]);
        $this->assertSame($this->item->id, $sold->first()['item_id']);
        $this->assertSame('5.0000', $sold->first()['quantity']);
        $this->assertSame(2, $sold->first()['invoices']);
        $this->assertSame(500_000, $sold->first()['amount']);
        $this->assertSame(500_000, $sold->first()['cost'], 'five at the average cost');

        $idle = app(ProductAnalytics::class)->rows(['view' => 'least_taken', 'days' => 90]);
        $this->assertSame([$other->id], $idle->pluck('item_id')->all(), 'the widget moved this week');

        $never = app(ProductAnalytics::class)->rows(['view' => 'never_sold']);
        $this->assertContains($other->id, $never->pluck('item_id')->all());
        $this->assertNotContains($this->item->id, $never->pluck('item_id')->all());

        $turn = app(ProductAnalytics::class)->rows(['view' => 'turnover', 'period' => $period])->first();
        $this->assertSame('45.0000', $turn['on_hand']);
        $this->assertSame('0.11', $turn['turns']);
    }

    public function test_the_screens_open_for_purchasing_and_hide_cost_from_sales(): void
    {
        $this->stock($this->gudangJakarta, 5, date: today()->subDays(10)->toDateString());

        $this->actingAs($this->inventory);
        Livewire::test(StockAgePage::class)->assertOk()->assertSee($this->item->number)->assertSee(__('Value'));
        Livewire::test(AnalyticsPage::class)->assertOk();

        $this->actingAs($this->sales);
        Livewire::test(AnalyticsPage::class)->assertOk()->assertDontSee(__('Margin'));
    }
}
