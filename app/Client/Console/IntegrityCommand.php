<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Ops\Health\OpsAlerter;
use App\Client\Domain\Ops\Integrity\LedgerIntegrity;
use App\Client\Mail\IntegrityFindingsMessage;
use App\Domain\Shared\Locales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Compares every cache with its ledger; exit 0 clean, 1 drift. With --notify, the nightly run that mails the administrators while a drift stands. */
class IntegrityCommand extends Command
{
    protected $signature = 'central:integrity {--notify : Mail the administrators when there are findings}';

    protected $description = 'Check the journal, the stock cache, the settlement cache and the reservations against their ledgers';

    public function handle(LedgerIntegrity $integrity): int
    {
        $findings = $integrity->findings();
        if ($findings === []) {
            $this->info('OK: every cache agrees with its ledger.');

            return self::SUCCESS;
        }
        foreach ($findings as $finding) {
            $this->line('DRIFT '.$finding->line());
        }
        $this->error(count($findings).' difference(s). Nothing was changed.');
        if ($this->option('notify')) {
            $recipients = OpsAlerter::recipients();
            if ($recipients !== []) {
                Locales::using(Locales::companyDefault(), fn () => Mail::to($recipients)->send(new IntegrityFindingsMessage($findings)));
                $this->line('Mailed to '.implode(', ', $recipients).'.');
            }
            Log::warning('Ledger integrity findings', ['count' => count($findings), 'findings' => array_map(fn ($f) => $f->line(), $findings)]);
        }

        return self::FAILURE;
    }
}
