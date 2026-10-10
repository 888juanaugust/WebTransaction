<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Stock\OpnameScheduler;
use App\Client\Domain\SystemActor;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Filament\Pages\CountSheets;
use App\Client\Mail\CountSheetsMessage;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Company\CalendarFeed;
use App\Domain\Inventory\StockQuery;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockOpnameResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Count sheets on a cadence: the day's OUT movements, the semester's everything; the gudang counts, Purchasing approves. */
class OpnameScheduleTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
        $this->other = $this->sampleItem(['number' => 'ITM-OTHER', 'name' => 'Other part']);
        $this->stock($this->gudangJakarta, 7, $this->other, '2026-05-01');
        $this->stock($this->gudangSurabaya, 3, $this->other, '2026-05-01');
        $this->gudang = $this->member(CentralGroups::WAREHOUSE, [$this->jakarta]);
        app(WarehouseBinder::class)->bind($this->gudangJakarta, $this->gudang, $this->owner);
    }

    private Item $other;

    public function test_the_days_sheet_holds_only_what_went_out_of_that_warehouse_and_is_made_once(): void
    {
        $this->actingAs($this->owner);
        $order = $this->order(4);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->deliver($order, 4, $this->gudangJakarta, today()->toDateString());

        $this->artisan('central:opname-sheets')->assertSuccessful();
        $this->artisan('central:opname-sheets')->assertSuccessful();

        $sheet = StockOpnameResult::query()->whereHas('order', fn ($q) => $q->where('kind', 'daily'))->sole();
        $this->assertSame($this->gudangJakarta->id, $sheet->order->warehouse_id);
        $this->assertSame([$this->item->id], $sheet->lines()->pluck('item_id')->all(), 'only the widget moved today');
        $this->assertSame('46.0000', $sheet->lines()->first()->system_qty);
        $this->assertSame(SystemActor::user()->id, $sheet->created_by);
        $this->assertSame($this->gudang->name, $sheet->order->person_charged);
        $this->assertTrue($sheet->order->users()->whereKey($this->gudang->id)->exists());
        $this->assertNull(app(OpnameScheduler::class)->daily($this->gudangSurabaya, today()), 'nothing went out of Surabaya');
    }

    public function test_the_semester_sheet_holds_every_item_the_warehouse_has_had(): void
    {
        $this->artisan('central:opname-sheets', ['--semester' => true])->assertSuccessful();

        $sheets = StockOpnameResult::query()->with('order')->whereHas('order', fn ($q) => $q->where('kind', 'semester'))->get();
        $jakarta = $sheets->first(fn ($s) => $s->order->warehouse_id === $this->gudangJakarta->id);
        $surabaya = $sheets->first(fn ($s) => $s->order->warehouse_id === $this->gudangSurabaya->id);
        $this->assertEqualsCanonicalizing([$this->item->id, $this->other->id], $jakarta->lines()->pluck('item_id')->all());
        $this->assertSame([$this->other->id], $surabaya->lines()->pluck('item_id')->all());
    }

    public function test_the_gudang_counts_its_own_sheet_and_purchasing_approves_the_variance(): void
    {
        $sheet = app(OpnameScheduler::class)->semester($this->gudangJakarta, today());
        $widget = $sheet->lines()->where('item_id', $this->item->id)->first();

        $this->actingAs($this->gudang);
        Livewire::test(CountSheets::class)->assertOk()->assertCanSeeTableRecords([$sheet])
            ->callTableAction('count', $sheet, ['lines' => [['line_id' => $widget->id, 'item' => 'x', 'unit' => 'PCS', 'counted' => 48]]])
            ->assertHasNoTableActionErrors();
        $sheet->refresh();
        $this->assertSame('48.0000', $widget->fresh()->base_quantity);
        $this->assertNotNull($sheet->counted_at);
        $this->assertSame($this->gudang->id, $sheet->counted_by);
        Livewire::test(CountSheets::class)->assertTableActionHidden('approve', $sheet);

        $surabayaSheet = app(OpnameScheduler::class)->semester($this->gudangSurabaya, today());
        Livewire::test(CountSheets::class)->assertCanNotSeeTableRecords([$surabayaSheet]);

        $this->actingAs($this->inventory);
        Livewire::test(CountSheets::class)->callTableAction('approve', $sheet)->assertHasNoTableActionErrors();
        $this->assertTrue($sheet->fresh()->isApproved());
        $this->assertSame('48.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id), 'the variance posted');
    }

    public function test_the_morning_reminder_tells_the_gudang_and_purchasing_what_waits(): void
    {
        $sheet = app(OpnameScheduler::class)->semester($this->gudangJakarta, today()->subDay());

        $this->artisan('central:count-reminders')->assertSuccessful();

        Mail::assertSent(CountSheetsMessage::class, fn (CountSheetsMessage $m) => $m->what === 'count' && $m->hasTo($this->gudang->email) && $m->hasTo($this->inventory->email));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->gudang->id)->count());
        $this->assertNotEmpty(CalendarFeed::between(today()->subDays(2)->toImmutable(), today()->toImmutable())[today()->subDay()->toDateString()] ?? [], 'the sheet is on the calendar');
    }
}
