<?php

namespace Tests\Feature\Client\Ops;

use App\Client\Domain\Ops\Integrity\IntegrityFinding;
use App\Client\Domain\Ops\Integrity\LedgerIntegrity;
use App\Client\Mail\IntegrityFindingsMessage;
use App\Domain\Approval\ApprovalEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The sweep: clean after a real flow, and every kind of drift named when a cache is pushed off its ledger. Nothing repaired. */
class LedgerIntegrityTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50);
        Mail::fake();
    }

    /** @return list<string> */
    private function checks(): array
    {
        return array_values(array_unique(array_map(fn (IntegrityFinding $f) => $f->check, app(LedgerIntegrity::class)->findings())));
    }

    public function test_a_real_flow_leaves_every_cache_agreeing_with_its_ledger(): void
    {
        $order = $this->order(5, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->deliver($order, 2);
        $this->invoice(3, 100_000);

        $this->assertSame([], app(LedgerIntegrity::class)->findings());
        $this->artisan('central:integrity', ['--notify' => true])->assertSuccessful()->expectsOutputToContain('OK');
        Mail::assertNothingSent();
    }

    public function test_each_kind_of_drift_is_named_and_the_nightly_run_mails_the_administrators(): void
    {
        $order = $this->order(5, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $invoice = $this->invoice(3, 100_000);

        DB::table('item_costs')->where('item_id', $this->item->id)->where('warehouse_id', $this->gudangJakarta->id)->update(['qty_on_hand' => 999]);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['paid_amount' => 50_000, 'payment_status' => 'partial']);
        DB::table('stock_reservations')->insert(['sales_order_id' => $order->id, 'sales_order_line_id' => $order->lines()->first()->id, 'item_id' => $this->item->id, 'warehouse_id' => $this->gudangSurabaya->id, 'kind' => 'released', 'quantity' => -1, 'reason' => 'drift', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $entry = DB::table('journal_entries')->orderBy('id')->first();
        DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER ALL');
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->orderBy('id')->limit(1)->update(['debit' => DB::raw('debit + 1')]);
        DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER ALL');

        $findings = app(LedgerIntegrity::class)->findings();
        $this->assertEqualsCanonicalizing([LedgerIntegrity::JOURNAL, LedgerIntegrity::STOCK, LedgerIntegrity::SETTLEMENT, LedgerIntegrity::RESERVATIONS], $this->checks());
        $lines = array_map(fn (IntegrityFinding $f) => $f->line(), $findings);
        $this->assertStringContainsString('cache 999.0000', implode("\n", $lines));
        $this->assertStringContainsString('cache paid 50000 (partial), allocations 0 (unpaid)', implode("\n", $lines));
        $this->assertStringContainsString('holds -1', implode("\n", $lines));
        $this->assertStringContainsString('trial balance', implode("\n", $lines));

        $this->artisan('central:integrity', ['--notify' => true])->assertFailed()->expectsOutputToContain('DRIFT [stock]');
        Mail::assertSentCount(1);
        Mail::assertSent(IntegrityFindingsMessage::class, function (IntegrityFindingsMessage $mail): bool {
            $this->assertTrue($mail->hasTo($this->owner->email));
            $this->assertStringContainsString('Nothing was changed', $mail->render());

            return true;
        });
        $this->assertSame('999.0000', (string) DB::table('item_costs')->where('item_id', $this->item->id)->where('warehouse_id', $this->gudangJakarta->id)->value('qty_on_hand'), 'repaired nothing');
    }

    public function test_goods_held_beyond_the_shelf_are_a_finding_unless_negative_stock_is_allowed(): void
    {
        $order = $this->order(5, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        DB::table('item_costs')->where('item_id', $this->item->id)->where('warehouse_id', $this->gudangJakarta->id)->update(['qty_on_hand' => 2]);

        $lines = array_map(fn (IntegrityFinding $f) => $f->line(), app(LedgerIntegrity::class)->findings());
        $this->assertStringContainsString('5.0000 held, 2.0000 on hand', implode("\n", $lines));
    }
}
