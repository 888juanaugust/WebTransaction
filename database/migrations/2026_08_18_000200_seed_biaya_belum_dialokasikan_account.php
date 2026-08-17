<?php

declare(strict_types=1);

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Landed cost needs somewhere for a charge to wait.
 *
 * Same shape as every other chart addition: the seeder fills in what is
 * missing and leaves alone anything an accountant has renamed.
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
