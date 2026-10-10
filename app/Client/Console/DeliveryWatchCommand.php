<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Orders\DeliveryWatch;
use Illuminate\Console\Command;

/** Reports every order accepted the watch days ago or more, once, in one digest; the schedule runs it every morning. */
class DeliveryWatchCommand extends Command
{
    protected $signature = 'central:delivery-watch';

    protected $description = 'Tell the administrators whether the goods of orders accepted 30 days ago went out';

    public function handle(DeliveryWatch $watch): int
    {
        $n = $watch->digest();
        $this->info($n === 0 ? __('Nothing to report.') : __(':n order(s) reported.', ['n' => $n]));

        return self::SUCCESS;
    }
}
