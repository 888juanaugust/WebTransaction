<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Customers\Birthdays;
use App\Client\Mail\ReminderMessage;
use App\Domain\Company\CalendarFeed;
use App\Domain\Privacy\PersonalData;
use App\Models\Sales\CustomerContact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** A contact person's birthday: reminders to the team three days before and on the day, once; the calendar; the privacy export. */
class BirthdayTest extends TestCase
{
    use OrderFlow;

    private CustomerContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
        $this->travelTo('2026-10-10');
        $this->contact = $this->customer->contacts()->create(['name' => 'Pak Hartono', 'position' => 'Owner', 'mobile_phone' => '0812', 'birth_date' => '1975-10-13', 'sort' => 0]);
    }

    public function test_the_team_is_told_three_days_before_and_on_the_day_once_each(): void
    {
        $this->artisan('central:birthdays')->assertSuccessful();
        $this->artisan('central:birthdays')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(ReminderMessage::class, fn (ReminderMessage $m) => str_contains($m->subjectLine, 'Pak Hartono') && $m->hasTo($this->sales->email) && $m->hasTo($this->marketing->email) && $m->hasTo($this->owner->email) && ! $m->hasTo($this->finance->email));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->sales->id)->count());
        $this->assertSame(1, DB::table('birthday_notices')->where('kind', 'before')->count());

        $this->travelTo('2026-10-13');
        $this->artisan('central:birthdays')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(ReminderMessage::class, fn (ReminderMessage $m) => str_contains($m->subjectLine, 'Today'));
        $this->assertSame(2, DB::table('birthday_notices')->count());

        $this->travelTo('2027-10-13');
        $this->assertSame(1, app(Birthdays::class)->remind(CarbonImmutable::today()), 'next year again');
    }

    public function test_the_calendar_shows_the_birthday_to_the_team_within_their_branches(): void
    {
        $this->actingAs($this->sales);
        $events = CalendarFeed::between(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertNotEmpty(array_filter($events['2026-10-13'] ?? [], fn (array $e) => $e['kind'] === 'birthday' && str_contains($e['title'], 'Pak Hartono')));
        $this->assertArrayHasKey('birthday', CalendarFeed::extraKinds());

        $this->actingAs($this->gudangUser ?? $this->member(CentralGroups::WAREHOUSE, [$this->jakarta]));
        $events = CalendarFeed::between(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertEmpty(array_filter($events['2026-10-13'] ?? [], fn (array $e) => $e['kind'] === 'birthday'), 'no Customers right, no birthdays');
    }

    public function test_the_birth_date_leaves_with_the_customers_personal_data(): void
    {
        $this->actingAs($this->owner);
        $export = PersonalData::json($this->customer);
        $this->assertStringContainsString('1975-10-13', $export);
    }
}
