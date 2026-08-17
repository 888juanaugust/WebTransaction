<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting output VAT: the file that goes to Coretax, and the numbers that
 * come back.
 *
 * There is no API. A person exports a file, uploads it, and Coretax assigns a
 * nomor seri faktur pajak to each faktur, which then has to land back on our
 * invoice. That round trip is the whole feature, and the reason an export is a
 * document here rather than a download button.
 *
 * **An export is a record, not a file.** Somebody will eventually ask which
 * invoices were reported for August and what came back for each — during an
 * audit, or when a customer says they never got a faktur. A button that
 * streams a CSV and forgets cannot answer that. So each run is a row, each
 * invoice it covered is a row under it, and the NSFP is written against the
 * line as well as onto the invoice.
 *
 * Re-exporting a month is allowed and is not an error: fakturs get rejected,
 * a customer's NPWP turns out to be wrong, a correction is filed. What is not
 * allowed is two invoices claiming the same NSFP, which is why that index is
 * unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One NSFP belongs to exactly one faktur. Duplicating one means two
         * invoices reported under a single serial number — a discrepancy the
         * tax office finds and we do not, because nothing in our own books
         * looks wrong.
         *
         * Nullable, so the index only constrains numbers that came back.
         */
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('nsfp');
        });

        Schema::create('faktur_exports', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            /*
             * The tax period, not the export date. A faktur belongs to the
             * masa pajak of its invoice, and a month is often exported in the
             * following one — so both are recorded and neither is derived.
             */
            $table->unsignedSmallInteger('masa_pajak');
            $table->unsignedSmallInteger('tahun_pajak');

            // Which layout was written. Stored per export because the answer
            // may change: see FakturWriter for why this is not a constant.
            $table->string('format', 20);

            $table->unsignedInteger('jumlah_faktur')->default(0);
            $table->bigInteger('total_dpp_rupiah')->default(0);
            $table->bigInteger('total_ppn_rupiah')->default(0);

            // Kept forever, like every other file we hand to somebody else.
            // What was filed matters more than what we could regenerate.
            $table->string('file_path')->nullable();

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['tahun_pajak', 'masa_pajak']);
        });

        Schema::create('faktur_export_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faktur_export_id')->constrained('faktur_exports')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');

            /*
             * The reference written into the file, which is what Coretax hands
             * back beside each assigned number. Snapshotted rather than joined
             * so a returned file can be matched even if the invoice is later
             * renumbered — which should never happen, and is exactly the kind
             * of never that turns up once.
             */
            $table->string('referensi', 30);

            // Filled when the number comes back. Null means still waiting.
            $table->string('nsfp', 30)->nullable();
            $table->timestamp('nsfp_recorded_at')->nullable();

            $table->timestamps();

            $table->unique(['faktur_export_id', 'invoice_id']);
            $table->index('referensi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faktur_export_lines');
        Schema::dropIfExists('faktur_exports');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['nsfp']);
        });
    }
};
