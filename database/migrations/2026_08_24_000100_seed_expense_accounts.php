<?php

declare(strict_types=1);

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Give the expense side of the laba rugi more than one line.
 *
 * Until now every cost that was not the goods themselves landed in a single
 * `6-1000 Beban Operasional`. The statement balanced and told nobody anything:
 * "Beban Operasional Rp 47.000.000" is not an answer to where the money went,
 * and the only way to find out afterwards is to read a year of journal lines
 * and re-code them by hand.
 *
 * Done now, before staff enter real transactions, because that is the whole
 * difference in cost. Adding accounts later is a migration like this one;
 * re-coding history that has already been reported is not.
 *
 * `ProfitAndLoss` needs no change — it groups the expense side by the account's
 * parent and treats everything that is not the cost-of-sales group as operating
 * expense, precisely so a group added later appears without being listed
 * anywhere by hand.
 *
 * The seeder `firstOrCreate`s, so an accountant who has renamed an account
 * keeps their name.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ChartOfAccountsSeeder)->run();
    }

    public function down(): void
    {
        // Accounts are not dropped. See the original chart migration.
    }
};
