<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closed accounting periods.
 *
 * The ledger already refuses an unbalanced entry and never edits a posted row.
 * Neither of those stops the thing that actually goes wrong in a small
 * business: a document entered three weeks late, dated to when it happened,
 * quietly restating a month whose figures have already gone to the accountant
 * and onto a tax return. Nothing is broken and nothing is edited — last
 * month's profit is simply a different number than it was, and nobody knows
 * until somebody compares two printouts.
 *
 * A closed period is a date range the ledger will not accept an entry into.
 * That is the whole feature; the year-end closing entry is a consequence of it.
 *
 * Only closed periods get a row. An absent period is open, which means no
 * seeding, no backfilling, and no way for the calendar to disagree with itself
 * about a month nobody has touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            // Calendar months. The fiscal year is the calendar year, which is
            // the default for a PT or CV unless the DJP has approved otherwise.
            $table->smallInteger('tahun');
            $table->smallInteger('bulan');

            $table->foreignId('closed_by')->constrained('users');
            $table->timestamp('closed_at')->useCurrent();
            $table->text('catatan')->nullable();

            /*
             * The entry that zeroed income and expense into Laba Ditahan. Only
             * a December close writes one, and only when there was something
             * to close — a year with no trading produces no entry rather than
             * an entry for nil.
             */
            $table->foreignId('closing_entry_id')->nullable()->constrained('journal_entries');

            $table->timestamps();

            /*
             * One row per month, and it is the lock itself rather than a record
             * of one: closing twice is prevented by the database, not by a
             * check that two people can both pass.
             */
            $table->unique(['tahun', 'bulan']);
            $table->index(['tahun', 'bulan', 'id']);
        });

        /*
         * Reopening is rare, consequential, and the thing an auditor asks
         * about — so it is a log of its own rather than columns on the row
         * above that a second reopening would overwrite.
         */
        Schema::create('accounting_period_reopenings', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('tahun');
            $table->smallInteger('bulan');

            $table->foreignId('reopened_by')->constrained('users');
            $table->text('alasan');

            // The reversal of that period's closing entry, where there was one.
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_reopenings');
        Schema::dropIfExists('accounting_periods');
    }
};
