<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Ops\Health\OpsAlerter;
use App\Client\Domain\Ops\Health\OpsHealth;
use App\Client\Domain\Ops\Health\OpsStatus;
use Illuminate\Console\Command;

/** Prints every health check; exit 0 healthy, 1 warning, 2 critical. With --alert, the hourly sweep that mails the administrators. */
class HealthCommand extends Command
{
    protected $signature = 'central:health {--alert : Mail the administrators when something is critical (once per incident)}';

    protected $description = 'Check the database, Redis, the queue, failed jobs, the scheduler, the backups and the disk';

    public function handle(OpsHealth $health, OpsAlerter $alerter): int
    {
        foreach ($health->checks() as $check) {
            $this->line(sprintf('%-9s %-28s %s', strtoupper($check->status->value), $check->title, $check->finding));
        }
        if ($this->option('alert') && $alerter->sweep()) {
            $this->line('Alert mailed.');
        }

        return match ($health->worst()) {
            OpsStatus::Healthy => 0,
            OpsStatus::Warning => 1,
            OpsStatus::Critical => 2,
        };
    }
}
