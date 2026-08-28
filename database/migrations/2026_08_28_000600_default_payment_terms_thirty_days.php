<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The faktur's printed due date is 30 days — the organisation's standard
 * term under the credit-sales rules. Distinct from the aging lines (notice
 * at 120 days, freeze past 150): this is the promise on the paper, those
 * are what happens when the promise is long broken.
 *
 * Customers still carry their own negotiated term; only the default moves,
 * and the zeros — "due on issue", which nobody ever meant — move with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE companies ALTER COLUMN payment_terms_days SET DEFAULT 30');
        DB::table('companies')->where('payment_terms_days', 0)->update(['payment_terms_days' => 30]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies ALTER COLUMN payment_terms_days SET DEFAULT 0');
    }
};
