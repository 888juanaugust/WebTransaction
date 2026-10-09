<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Debt\DebtNotices;
use App\Client\Jobs\SendDebtNotice;
use App\Models\Sales\SalesInvoice;
use Illuminate\Console\Command;

/** Queues the aging notice for every invoice due one; the schedule runs it nightly. */
class DebtNoticesCommand extends Command
{
    protected $signature = 'central:debt-notices';

    protected $description = 'Send the aging notice for every unpaid invoice older than the notice days';

    public function handle(DebtNotices $notices): int
    {
        $due = $notices->due();
        foreach ($due as $invoice) {
            /** @var SalesInvoice $invoice */
            SendDebtNotice::dispatch($invoice->id);
        }
        $this->info(count($due).' notice(s) queued.');

        return self::SUCCESS;
    }
}
