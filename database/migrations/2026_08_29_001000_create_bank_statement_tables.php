<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi bank — the statement file itself, read into the reconciliation.
 *
 * Until now the rekening koran existed in this system only as one typed
 * number: its closing balance. Every line on it was matched against the books
 * by eye, one tick at a time. These tables let the file in: upload the CSV the
 * bank's portal exports, parse it into lines, and let the machine propose the
 * matches — a statement line and a journal line that agree on amount and
 * direction within a few days of each other are almost certainly the same
 * money.
 *
 * The import belongs to a draft reconciliation and dies with it: a discarded
 * draft hands back its ticks, and statement lines whose matches point at those
 * ticks would be orphans telling a story about a reconciliation that no longer
 * exists. The raw file on disk survives regardless, same rule as the price
 * list importer — bytes that entered the building are kept.
 *
 * What this is *not*: an automatic bookkeeper. A match is only ever proposed;
 * a person confirms it, and the confirmation is exactly the tick they would
 * have made by hand. Money enters the payment ledger only through the same
 * `PaymentLedger` door as always — a statement line can *pre-fill* that door,
 * never bypass it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();

            // The uploaded bytes, kept forever under storage — the import row
            // records where, so a match can always be traced to its source.
            $table->string('source_file_path', 500);
            $table->string('original_name', 255);

            $table->string('status', 12)->default('selesai'); // selesai | gagal

            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->unsignedInteger('jumlah_error')->default(0);

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_statement_import_id')
                ->constrained('bank_statement_imports')
                ->cascadeOnDelete();

            // Position in the file, for showing the statement in its own order
            // and for pointing a person at the row that failed to parse.
            $table->unsignedInteger('urutan');

            // Nullable because an unparseable row is still recorded — as an
            // error line carrying its raw text, not silently dropped.
            $table->date('tanggal')->nullable();
            $table->string('uraian', 500);
            $table->string('arah', 10)->nullable(); // masuk | keluar
            $table->bigInteger('amount_rupiah')->nullable();
            $table->bigInteger('saldo_rupiah')->nullable();

            // belum | tercocok | diabaikan | error
            $table->string('status', 12)->default('belum');

            /*
             * The match, once a person confirms one. journal_line_id is the
             * Bank leg this statement line turned out to be; payment_entry_id
             * is set only when the statement line itself caused the payment to
             * be recorded (money in that the books had not seen).
             */
            $table->foreignId('journal_line_id')->nullable()
                ->constrained('journal_lines')->restrictOnDelete();
            $table->foreignId('payment_entry_id')->nullable()
                ->constrained('payment_entries')->restrictOnDelete();

            // Parse-error text, or the reason a line was set aside.
            $table->string('keterangan', 500)->nullable();

            $table->foreignId('matched_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();

            $table->unique(['bank_statement_import_id', 'urutan']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};
