<?php

declare(strict_types=1);

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The chart of accounts ships with the schema, not with the seeders.
 *
 * Every posting rule names an account by code, so a database without these
 * rows cannot issue an invoice. Leaving them to `db:seed` means a deployment
 * that forgets one command has a system where the first sale fails — and fails
 * at the end of a transaction that had already passed the credit check and the
 * stock reservation. Reference data the domain cannot run without belongs with
 * the tables.
 *
 * The seeding is idempotent and only fills in what is missing, so an account
 * an accountant has renamed keeps its name.
 *
 * This calls application code, which a migration usually should not: if
 * AccountCode changes shape later, this file changes meaning with it. The
 * alternative is a literal copy of the chart here that would drift from the
 * constants the posting rules use, and a silent drift between those two is a
 * worse failure than a loud one. When the chart grows, add another migration
 * that calls the same seeder again rather than editing this one — it is
 * idempotent precisely so that works.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ChartOfAccountsSeeder)->run();
    }

    public function down(): void
    {
        // Accounts are not dropped. Journal lines reference them, and a chart
        // row with entries behind it is evidence rather than configuration.
    }
};
