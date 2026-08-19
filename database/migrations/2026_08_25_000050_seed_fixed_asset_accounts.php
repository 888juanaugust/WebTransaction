<?php

declare(strict_types=1);

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Aktiva Tetap, Akumulasi Penyusutan, and the gain or loss on selling one.
 *
 * Runs before the tables that need them, so a register cannot exist with
 * nowhere to post.
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
