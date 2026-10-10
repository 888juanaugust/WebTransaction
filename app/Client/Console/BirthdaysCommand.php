<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Customers\Birthdays;
use Illuminate\Console\Command;

/** Every morning: the birthdays in three days and today, to the customer's team and the administrators. */
class BirthdaysCommand extends Command
{
    protected $signature = 'central:birthdays';

    protected $description = 'Remind the team of the birthdays of the people at their customers';

    public function handle(Birthdays $birthdays): int
    {
        $n = $birthdays->remind();
        $this->info($n === 0 ? __('Nothing to report.') : __(':n reminder(s) sent.', ['n' => $n]));

        return self::SUCCESS;
    }
}
