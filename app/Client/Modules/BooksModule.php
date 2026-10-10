<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\BooksReminderCommand;
use App\Client\Domain\Books\YearEnd;
use App\Client\Models\FiscalYearClose;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Company\CalendarFeed;
use App\Domain\Company\FiscalYear;
use App\Models\GeneralLedger\AccountingPeriod;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;

/** The year-end lock and the month-close reminder. */
final class BooksModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-books';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::YearEnd];
    }

    public static function morphMap(): array
    {
        return ['fiscal_year_close' => FiscalYearClose::class];
    }

    public static function boot(ModuleContext $context): void
    {
        // A month inside a closed fiscal year never reopens; the year is reopened first, by the Owner, with a reason.
        AccountingPeriod::updating(function (AccountingPeriod $period) use ($context): void {
            if ($period->isDirty('status') && $period->status === AccountingPeriod::OPEN && $period->getOriginal('status') === AccountingPeriod::CLOSED) {
                $date = CarbonImmutable::create((int) $period->year, (int) $period->month, 1);
                if ($context->app->make(YearEnd::class)->isClosed($date)) {
                    throw new RuntimeException(__('The fiscal year of :month is closed; reopen the year on Year-end Close first.', ['month' => $date->translatedFormat('F Y')]));
                }
            }
        });

        // The calendar marks the year end for whoever may see the month-end process.
        CalendarFeed::extend('year-end', fn () => __('Year end'), 'bg-slate-100 text-slate-800', function (CarbonImmutable $from, CarbonImmutable $until, callable $add): void {
            $user = auth()->user();
            if ($user !== null && ! app(HakAkses::class)->allows($user, MenuKey::MonthEndProcess, Hak::View)) {
                return;
            }
            for ($end = FiscalYear::endOf($from); $end->lte($until); $end = FiscalYear::endOf($end->addDay())) {
                if ($end->gte($from)) {
                    $add($end->toDateString(), 'year-end', __('Year end: close the year once every month is closed and depreciation is posted'));
                }
            }
        });
    }

    public static function commands(): array
    {
        return [BooksReminderCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command('central:books-reminder')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
    }
}
