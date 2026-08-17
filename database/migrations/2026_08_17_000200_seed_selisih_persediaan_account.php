<?php

declare(strict_types=1);

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Stok opname needs somewhere to put a variance.
 *
 * The chart ships with the schema, so a new account added to AccountCode
 * reaches existing databases the same way the original chart did — by running
 * the seeder again. It only fills in what is missing, so nothing an accountant
 * has renamed is touched.
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
