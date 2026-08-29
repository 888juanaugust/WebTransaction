<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\OpsCheck;
use App\Domain\Ops\OpsHealth;
use App\Domain\Ops\OpsStatus;
use Illuminate\Console\Command;

/**
 * The box examined from the terminal — same checks as the Owner's widget.
 *
 * Exit codes are the contract: 0 healthy, 1 degraded (waspada), 2 broken
 * (gawat) — so a cron line or an external prober can page on the number
 * without parsing Indonesian.
 */
class OpsCheckCommand extends Command
{
    protected $signature = 'ops:check';

    protected $description = 'Periksa kesehatan sistem: database, redis, antrean, scheduler, cadangan, disk';

    public function handle(OpsHealth $health): int
    {
        foreach ($health->checks() as $check) {
            $this->printCheck($check);
        }

        return match ($health->worst()) {
            OpsStatus::Sehat => 0,
            OpsStatus::Waspada => 1,
            OpsStatus::Gawat => 2,
        };
    }

    private function printCheck(OpsCheck $check): void
    {
        $label = match ($check->status) {
            OpsStatus::Sehat => '<info>SEHAT  </info>',
            OpsStatus::Waspada => '<comment>WASPADA</comment>',
            OpsStatus::Gawat => '<error>GAWAT  </error>',
        };

        $this->line(sprintf('%s %-22s %s', $label, $check->judul, $check->temuan));
    }
}
