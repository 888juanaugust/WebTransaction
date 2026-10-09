<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Ops\Launch\LaunchReadiness;
use Illuminate\Console\Command;

/** Prints every readiness item; exits 0 only when nothing is outstanding. Informational at the end of a deploy. */
class LaunchCheckCommand extends Command
{
    protected $signature = 'central:launch-check';

    protected $description = 'List what still stands between the system and going live';

    public function handle(LaunchReadiness $readiness): int
    {
        foreach ($readiness->checks() as $check) {
            $this->line(sprintf('%-5s %-9s %s', $check->passed ? 'PASS' : 'OPEN', $check->automatic ? 'checked' : 'attested', $check->title));
            if (! $check->passed) {
                if ($check->finding) {
                    $this->line('      '.$check->finding);
                }
                if ($check->action) {
                    $this->line('      → '.$check->action);
                }
            } elseif (! $check->automatic) {
                $this->line('      '.$check->attestedBy.', '.$check->attestedAt.': '.$check->note);
            }
        }
        $open = $readiness->outstanding();
        $this->newLine();
        $open === 0 ? $this->info('Ready: nothing outstanding.') : $this->warn("{$open} item(s) outstanding.");

        return $open === 0 ? self::SUCCESS : self::FAILURE;
    }
}
