<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Notify\Notify;
use App\Client\Domain\Orders\DeliveryWatch;
use App\Client\Filament\Pages\DeliveryWatch as DeliveryWatchPage;
use App\Client\Mail\DeliveryDigestMessage;
use App\Client\Models\OrderDeliveryNotice;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Company\CalendarFeed;
use App\Models\Sales\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The 30-day watch: once per accepted order, by bell and by mail, delivered or not; the worklist and the calendar show it. */
class DeliveryWatchTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
    }

    private function accepted(int $daysAgo, int $qty = 2): SalesOrder
    {
        $order = $this->order($qty);
        $this->assertTrue(app(ApprovalEngine::class)->approve($order, $this->marketing));
        $order->forceFill(['approved_at' => today()->subDays($daysAgo)->setTime(10, 0)])->saveQuietly();

        return $order->fresh();
    }

    public function test_the_digest_reports_each_order_past_the_watch_days_once_delivered_or_not(): void
    {
        $this->actingAs($this->owner);
        $undelivered = $this->accepted(31);
        $delivered = $this->accepted(45);
        $this->deliver($delivered, 2, $this->gudangJakarta, today()->toDateString());
        $young = $this->accepted(10);

        $this->assertSame([$delivered->id, $undelivered->id], app(DeliveryWatch::class)->due()->pluck('id')->all());

        $this->artisan('central:delivery-watch')->assertSuccessful();
        $this->artisan('central:delivery-watch')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(DeliveryDigestMessage::class, function (DeliveryDigestMessage $mail) use ($undelivered, $delivered): bool {
            $numbers = array_map(fn (array $r) => $r['order']->number, $mail->rows);

            return count($mail->rows) === 2 && in_array($undelivered->number, $numbers, true) && in_array($delivered->number, $numbers, true)
                && $mail->hasTo($this->owner->email) && $mail->hasTo($this->marketing->email) && ! $mail->hasTo($this->sales->email);
        });
        $notices = OrderDeliveryNotice::query()->orderBy('sales_order_id')->get()->keyBy('sales_order_id');
        $this->assertCount(2, $notices);
        $this->assertSame('pending', $notices[$undelivered->id]->state);
        $this->assertSame('delivered', $notices[$delivered->id]->state);
        $this->assertSame(31, $notices[$undelivered->id]->days);
        $this->assertContains($this->owner->email, $notices[$undelivered->id]->sent_to);
        $this->assertNull($notices->get($young->id));
        $this->assertSame(0, app(DeliveryWatch::class)->due()->count(), 'nothing left');

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->owner->id)->count(), 'one bell for the administrator');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->marketing->id)->count(), 'one bell for the marketing seat');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->sales->id)->count());
    }

    public function test_the_summary_names_the_deliveries_and_the_holding_warehouse(): void
    {
        $this->actingAs($this->owner);
        $order = $this->accepted(40, 5);
        $delivery = $this->deliver($order, 2, $this->gudangJakarta, today()->toDateString());

        $summary = app(DeliveryWatch::class)->summary($order->fresh());

        $this->assertSame('partial', $summary['state']);
        $this->assertSame('2.0000', $summary['delivered']);
        $this->assertSame('5.0000', $summary['ordered']);
        $this->assertSame([$delivery->number], $summary['deliveries']);
        $this->assertSame([$this->gudangJakarta->name], $summary['warehouses'], 'the rest is still held');
        $this->assertSame(40, $summary['days']);
    }

    public function test_the_worklist_lists_the_overdue_orders_and_the_calendar_marks_the_day(): void
    {
        $this->actingAsAdmin();
        $order = $this->accepted(32);
        $this->accepted(3);

        Livewire::test(DeliveryWatchPage::class)->assertOk()->assertCanSeeTableRecords([$order])->assertCountTableRecords(1);

        $events = CalendarFeed::between(CarbonImmutable::today()->subDays(5), CarbonImmutable::today()->addDays(40));
        $marked = $events[today()->subDays(2)->toDateString()] ?? [];
        $this->assertNotEmpty(array_filter($marked, fn (array $e) => $e['kind'] === 'delivery-watch' && str_contains($e['title'], $order->number)), 'the day it crossed 30 days');
        $this->assertArrayHasKey('delivery-watch', CalendarFeed::extraKinds());
    }

    public function test_notify_sends_a_bell_per_user_and_one_mail(): void
    {
        $sent = app(Notify::class)->send([$this->owner, $this->finance, $this->owner], 'Hello', 'Body');

        $this->assertEqualsCanonicalizing([$this->owner->email, $this->finance->email], $sent);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->owner->id)->count(), 'a duplicate user gets one bell');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->finance->id)->count());
        $admins = app(Notify::class)->administrators();
        $this->assertContains($this->owner->id, $admins->pluck('id')->all());
        $this->assertTrue($admins->every(fn ($u) => $u->isAdministrator() && $u->is_active), 'every active administrator, nobody else');
    }
}
