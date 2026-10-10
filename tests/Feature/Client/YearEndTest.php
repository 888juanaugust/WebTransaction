<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Books\YearEnd;
use App\Client\Domain\Stock\OpnameScheduler;
use App\Client\Filament\Pages\YearEndClose;
use App\Client\Mail\ReminderMessage;
use App\Client\Models\FiscalYearClose;
use App\Domain\Inventory\OpnameApprover;
use App\Domain\Posting\PeriodLock;
use App\Models\Company\AuditLog;
use App\Models\GeneralLedger\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The year-end lock: a checklist, the Owner's close, no reopening of a month inside a closed year, the month-close reminder. */
class YearEndTest extends TestCase
{
    use OrderFlow;

    private CarbonImmutable $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
        $this->travelTo('2027-02-10'); // last year (2026) has ended
        $this->year = CarbonImmutable::parse('2026-01-01');
        $this->stock($this->gudangJakarta, 5, date: '2026-03-01');
    }

    private function closeEveryMonth(): void
    {
        $lock = app(PeriodLock::class);
        for ($m = $this->year; $m->year === 2026; $m = $m->addMonth()) {
            $lock->close($m->year, $m->month, $this->owner->id);
        }
    }

    private function approveSemesters(): void
    {
        $this->actingAs($this->owner);
        foreach (['2026-01-01', '2026-07-01'] as $day) {
            $sheet = app(OpnameScheduler::class)->semester($this->gudangJakarta, $day);
            if ($sheet !== null) {
                $sheet->forceFill(['counted_at' => now(), 'counted_by' => $this->owner->id])->saveQuietly();
                app(OpnameApprover::class)->approve($sheet, $this->inventory);
            }
        }
    }

    public function test_the_checklist_names_what_stands_in_the_way_and_the_close_refuses_until_it_clears(): void
    {
        $checks = collect(app(YearEnd::class)->checklist($this->year))->keyBy('key');
        $this->assertFalse($checks['months']->passed);
        $this->assertStringContainsString('January 2026', $checks['months']->finding);
        $this->assertFalse($checks['counts']->passed);

        try {
            app(YearEnd::class)->close($this->year, $this->owner);
            $this->fail('closed with open items');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Not yet', $e->getMessage());
        }

        $this->closeEveryMonth();
        $this->approveSemesters();
        $checks = collect(app(YearEnd::class)->checklist($this->year))->keyBy('key');
        $this->assertTrue($checks['months']->passed);
        $this->assertTrue($checks['counts']->passed, $checks['counts']->finding ?? '');
        $this->assertTrue($checks['integrity']->passed);
    }

    public function test_the_owner_closes_the_year_and_no_month_of_it_reopens(): void
    {
        $this->closeEveryMonth();
        $this->approveSemesters();

        try {
            app(YearEnd::class)->close($this->year, $this->finance);
            $this->fail('finance closed the year');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('administrator', $e->getMessage());
        }

        $this->actingAs($this->owner);
        $close = app(YearEnd::class)->close($this->year, $this->owner, 'Books agreed with the accountant');
        $this->assertSame('2026-01-01', $close->fiscal_year_start->toDateString());
        $this->assertTrue(app(YearEnd::class)->isClosed('2026-06-15'));
        $this->assertTrue(AuditLog::query()->where('action', 'fiscal_year_closed')->exists());

        try {
            app(PeriodLock::class)->reopen(2026, 12);
            $this->fail('a month of a closed year reopened');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fiscal year', $e->getMessage());
        }
        $this->assertSame(AccountingPeriod::CLOSED, AccountingPeriod::query()->where('year', 2026)->where('month', 12)->value('status'));

        app(YearEnd::class)->reopen($this->year, $this->owner, 'A late invoice');
        $this->assertFalse(app(YearEnd::class)->isClosed('2026-06-15'));
        $this->assertSame(AccountingPeriod::OPEN, app(PeriodLock::class)->reopen(2026, 12)->status, 'reopens once the year is open');
        $this->assertNull(FiscalYearClose::query()->first());
    }

    public function test_the_screen_shows_the_checklist_and_the_close_button_only_when_clear(): void
    {
        $this->actingAsAdmin();
        Livewire::test(YearEndClose::class)->assertOk()->assertSee(__('Every month of the year is closed'))->assertActionHidden('close');

        $this->closeEveryMonth();
        $this->approveSemesters();
        Livewire::test(YearEndClose::class)->assertActionVisible('close')->callAction('close', ['notes' => 'done'])->assertHasNoActionErrors();
        $this->assertTrue(app(YearEnd::class)->isClosed($this->year));
    }

    public function test_the_month_close_reminder_tells_finance_once_when_a_month_is_ten_days_past(): void
    {
        $this->travelTo('2027-02-12'); // January 2027 ended 12 days ago and is open
        $this->artisan('central:books-reminder')->assertSuccessful();
        $this->artisan('central:books-reminder')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(ReminderMessage::class, fn (ReminderMessage $m) => $m->hasTo($this->finance->email) && $m->hasTo($this->owner->email) && str_contains($m->subjectLine, 'January 2027'));
        $this->assertSame(1, DB::table('books_reminders')->where('period', '2027-01')->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->finance->id)->count());

        $this->travelTo('2027-02-05');
        DB::table('books_reminders')->delete();
        $this->artisan('central:books-reminder')->assertSuccessful();
        Mail::assertSentCount(1, 'not yet ten days past');
    }
}
