<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Stock\OpnameScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Drafts the day's count sheet per warehouse (what went out today), or the semester's full sheet on its first day. */
class OpnameSheetsCommand extends Command
{
    protected $signature = 'central:opname-sheets {--date= : The day to draft for (default today)} {--semester : Draft the full semester sheet instead of the day\'s}';

    protected $description = 'Draft the count sheets: the day\'s movements per warehouse, or the semester\'s full count';

    public function handle(OpnameScheduler $scheduler): int
    {
        $day = CarbonImmutable::parse((string) ($this->option('date') ?: today()->toDateString()));
        $semester = (bool) $this->option('semester') || ($day->day === 1 && in_array($day->month, (array) config('stock.semester_months', [1, 7]), true));
        $made = 0;
        foreach ($scheduler->warehouses() as $warehouse) {
            $sheet = $semester ? $scheduler->semester($warehouse, $day) : $scheduler->daily($warehouse, $day);
            if ($sheet !== null) {
                $made++;
                $this->line(sprintf('%-24s %s (%d)', $warehouse->name, $sheet->number, $sheet->lines()->count()));
            }
        }
        $this->info(__(':n sheet(s) ready.', ['n' => $made]));

        return self::SUCCESS;
    }
}
