<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\FixedAssets\DepreciationRun;
use App\Domain\Shared\Format;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Posts the monthly depreciation of every fixed asset in use; the schedule runs it on the month's last day. */
class DepreciateCommand extends Command
{
    protected $signature = 'erp:depreciate {until? : A month (YYYY-MM); the current month when omitted}';

    protected $description = 'Post the monthly depreciation of every fixed asset in use, up to the month given';

    public function handle(ModuleRegistry $modules, DepreciationRun $run): int
    {
        if (! $modules->isEnabled('fixed-assets')) {
            $this->warn('The Fixed assets module is switched off; nothing to depreciate.');

            return self::SUCCESS;
        }
        $until = $this->argument('until');
        // "!" starts from the 1st: a bare 'Y-m' takes today's day, and on the 31st November becomes December.
        $month = $until ? CarbonImmutable::createFromFormat('!Y-m', $until) : CarbonImmutable::today();
        if ($month === false || ($until && $month->format('Y-m') !== $until)) {
            $this->error("Give the month as YYYY-MM, e.g. 2026-11 (got \"{$until}\").");

            return self::FAILURE;
        }
        $result = $run->upTo($month);
        $this->info("Depreciation posted: {$result['posted']} month(s), ".Format::number($result['amount']).' in all.');
        foreach ($result['skipped'] as $note) {
            $this->warn("Skipped {$note}");
        }

        return self::SUCCESS;
    }
}
