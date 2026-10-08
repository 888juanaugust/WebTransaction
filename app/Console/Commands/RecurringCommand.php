<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Company\RecurringRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Makes the documents of every recurring transaction that is due; the schedule runs it each morning. */
class RecurringCommand extends Command
{
    protected $signature = 'erp:recurring {on? : Run every schedule due on or before this date (YYYY-MM-DD); today when omitted}';

    protected $description = 'Make the documents of every recurring transaction that is due';

    public function handle(RecurringRunner $runner): int
    {
        $on = $this->argument('on');
        ['made' => $made, 'failed' => $failed] = $runner->runDue($on ? CarbonImmutable::parse($on) : null);
        $this->info(count($made).' document(s) made from recurring transactions.');
        foreach ($made as $line) {
            $this->line("  {$line}");
        }
        foreach ($failed as $line) {
            $this->error("  Not run: {$line}");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
