<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Debt\DebtNotices;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Reports\Period;
use App\Domain\Reports\TradeReports;
use App\Domain\Sales\Contracts\AgingDate;
use App\Domain\Sales\CreditCheck;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** An invoice ages from the day its order was accepted (approved), not from the day Finance wrote it. */
class AcceptanceClockTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
        app(Preferensi::class)->set(PreferensiKey::CreditNoticeDays, 120);
        app(Preferensi::class)->set(PreferensiKey::CreditFreezeDays, 150);
    }

    /** An order approved $daysAgo days ago, invoiced today from its line. */
    private function acceptedInvoice(int $daysAgo, int $qty = 1): SalesInvoice
    {
        $order = $this->order($qty);
        $this->assertTrue(app(ApprovalEngine::class)->approve($order, $this->marketing));
        $order->forceFill(['approved_at' => today()->subDays($daysAgo)])->saveQuietly();
        $line = $order->lines()->first();

        $invoice = SalesInvoice::query()->create(['number' => 'INV-'.uniqid(), 'trans_date' => today()->toDateString(), 'customer_id' => $this->customer->id, 'branch_id' => $this->customer->branch_id, 'taxable' => true, 'inclusive_tax' => false,
            'payment_term_id' => $this->customer->payment_term_id, 'created_by' => $this->owner->id]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $line->item_id, 'quantity' => $qty, 'unit_id' => $line->unit_id, 'base_quantity' => $qty, 'unit_price' => $line->unit_price, 'tax_code_id' => $line->tax_code_id,
            'warehouse_id' => $this->gudangJakarta->id, 'source_line_type' => 'sales_order_line', 'source_line_id' => $line->id]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        return $invoice->fresh();
    }

    public function test_an_invoice_from_an_approved_order_ages_from_the_approval_and_is_due_a_term_after_it(): void
    {
        $invoice = $this->acceptedInvoice(40);

        $this->assertSame(today()->subDays(40)->toDateString(), (string) $invoice->accepted_at, 'stamped when the invoice was made');
        $this->assertSame(today()->subDays(40)->toDateString(), app(AgingDate::class)->issued($invoice)->toDateString());
        $this->assertSame(today()->subDays(10)->toDateString(), $invoice->due_date->toDateString(), 'Net 30 counted from the acceptance');
        $this->assertSame(40, app(CreditCheck::class)->oldestUnpaidDays($this->customer));
    }

    public function test_an_invoice_with_no_order_behind_it_ages_from_its_own_date(): void
    {
        $invoice = $this->invoice(1, 100_000, date: today()->subDays(5)->toDateString());

        $this->assertSame(today()->subDays(5)->toDateString(), (string) $invoice->accepted_at);
        $this->assertSame(today()->addDays(25)->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame(5, app(CreditCheck::class)->oldestUnpaidDays($this->customer));
    }

    public function test_the_notice_and_the_freeze_count_from_the_acceptance(): void
    {
        $invoice = $this->acceptedInvoice(121);

        $this->assertSame([$invoice->id], app(DebtNotices::class)->due()->pluck('id')->all(), 'noticed though written today');
        $this->assertTrue(app(CreditCheck::class)->needsNotice($this->customer));

        $order = $this->order(1);
        app(ApprovalEngine::class)->approve($order, $this->marketing); // 121 days: noticed, not frozen
        $this->assertSame(SalesOrder::APPROVED, $order->fresh()->approval_status);

        $invoice->forceFill(['accepted_at' => today()->subDays(151)])->save();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('frozen');
        app(CreditCheck::class)->assert($this->customer, 0);
    }

    public function test_the_receivable_aging_report_buckets_by_the_acceptance(): void
    {
        $this->acceptedInvoice(95);

        $rows = TradeReports::receivableAging(new Period(today()->startOfYear()->toImmutable(), today()->toImmutable()), 'invoice_date');
        $row = collect($rows)->firstWhere('id', $this->customer->id);

        $this->assertNotNull($row);
        $this->assertSame(95, $row['oldest_days']);
    }
}
