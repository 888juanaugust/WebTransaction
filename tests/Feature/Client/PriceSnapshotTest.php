<?php

namespace Tests\Feature\Client;

use App\Client\Domain\Pricing\PriceReason;
use App\Client\Models\CustomerPriceRule;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Domain\Approval\ApprovalEngine;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** At approval every line is stamped with why it got its price and from which version; an unpriced line stops the approval. */
class PriceSnapshotTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 20);
    }

    public function test_approval_stamps_the_reason_and_the_version_on_every_line(): void
    {
        $version = PriceListVersion::query()->create(['effective_from' => '2026-10-01', 'status' => PriceListVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $this->owner->id]);
        PriceListItem::query()->create(['version_id' => $version->id, 'item_id' => $this->item->id, 'price' => 120_000, 'qty_per_ctn' => 12]);
        $order = $this->order(5, $this->gudangJakarta, price: 120_000);

        app(ApprovalEngine::class)->approve($order, $this->marketing);

        $line = $order->fresh()->lines()->first();
        $this->assertSame(PriceReason::ListPrice->value, $line->price_reason);
        $this->assertSame($version->id, $line->price_list_version_id);
        $this->assertSame('120000.0000', $line->unit_price, 'the price itself is untouched');
    }

    public function test_a_customer_rule_is_the_reason_and_the_split_pieces_carry_the_stamp(): void
    {
        CustomerPriceRule::query()->create(['customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'price' => 95_000, 'reason' => 'tender']);
        $order = $this->order(5, $this->gudangJakarta, price: 95_000);

        app(ApprovalEngine::class)->approve($order, $this->marketing);

        $this->assertSame(PriceReason::CustomerPrice->value, $order->fresh()->lines()->first()->price_reason);
        $this->assertNull($order->fresh()->lines()->first()->price_list_version_id);
    }

    public function test_an_unpriced_line_stops_the_approval(): void
    {
        $this->item->update(['sell_price' => 0]);
        $order = $this->order(5, $this->gudangJakarta, price: 0);

        try {
            app(ApprovalEngine::class)->approve($order, $this->marketing);
            $this->fail('nothing prices the item');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no price in force', $e->getMessage());
        }
        $this->assertSame('awaiting', $order->fresh()->approval_status);
        $this->assertNull($order->fresh()->lines()->first()->price_reason);
    }
}
