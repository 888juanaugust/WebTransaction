<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Notify\Notify;
use App\Client\Mail\ReminderMessage;
use App\Domain\Posting\PeriodLock;
use App\Filament\Resources\GeneralLedger\AccountingPeriods\AccountingPeriodResource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** When the next month to close is more than ten days past, Finance and the administrators are told once. */
class BooksReminderCommand extends Command
{
    protected $signature = 'central:books-reminder {--days=10 : Days after the end of the month before the reminder}';

    protected $description = 'Remind Finance to close the month once it is more than ten days past';

    public function handle(PeriodLock $lock, Notify $notify): int
    {
        $next = CarbonImmutable::parse($lock->nextToClose());
        $dueOn = $next->endOfMonth()->addDays((int) $this->option('days'));
        if (today()->lte($dueOn)) {
            $this->info(__('Nothing to report.'));

            return self::SUCCESS;
        }
        $period = $next->format('Y-m');
        if (DB::table('books_reminders')->insertOrIgnore(['period' => $period, 'sent_at' => now()]) === 0) {
            $this->info(__('Already reminded for :period.', ['period' => $period]));

            return self::SUCCESS;
        }
        $title = __('The month of :month is still open', ['month' => $next->translatedFormat('F Y')]);
        $body = __('Close it on the Month-end Process screen once its entries are in; later months wait for it.');
        $url = AccountingPeriodResource::getUrl();
        $sent = $notify->send($notify->role(CentralGroups::FINANCE)->concat($notify->administrators()), $title, $body, $url, new ReminderMessage($title, [$body]));
        DB::table('books_reminders')->where('period', $period)->update(['sent_to' => json_encode($sent)]);
        $this->info(__('Reminded :n person(s) about :period.', ['n' => count($sent), 'period' => $period]));

        return self::SUCCESS;
    }
}
