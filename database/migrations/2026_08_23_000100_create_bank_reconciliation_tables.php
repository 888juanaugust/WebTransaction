<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi bank — proving the Bank account against the bank.
 *
 * Every other control account in this system is checked against a subledger we
 * also own: Piutang Usaha against our invoices, Persediaan against our costing.
 * Those checks prove the posting rules are consistent with each other. **Not
 * one of them can prove the money is actually there**, because both sides are
 * our own arithmetic.
 *
 * The bank is the only outside witness this business has. Reconciliation is
 * where the books meet it, and it is the control that catches the things
 * nothing else can: a fee nobody recorded, a standing order, a customer
 * transfer that arrived and was never matched, a payment recorded twice, and —
 * the reason auditors care — money leaving the account that no document
 * explains.
 *
 * The classic statement, which is what the arithmetic here computes:
 *
 *     saldo per buku besar
 *       − setoran dalam perjalanan   (we recorded it, the bank has not)
 *       + cek/giro beredar           (we recorded it out, the bank has not)
 *       = saldo yang seharusnya tertulis di rekening koran
 *
 * If that does not equal the closing balance printed on the statement, the
 * difference is something on the statement the books have never heard of —
 * which is exactly what the items table below is for.
 *
 * **One bank account.** The chart has a single Bank account and every payment
 * rule in the system posts to it, so that is what gets reconciled. A business
 * running two accounts would reconcile their combined balance, which is not
 * useful — see docs/MAP.md for what multi-bank would actually take, because it
 * is a change to every payment rule and not to this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            /*
             * The closing date printed on the statement. Everything the books
             * recorded up to and including this date is a candidate for
             * ticking; anything later cannot be on this statement.
             *
             * Unique: two reconciliations of the same account to the same date
             * are two people doing the same job, and the second one would tick
             * lines the first already claimed.
             */
            $table->date('tanggal_rekening')->unique();

            /*
             * The closing balance printed on the statement, typed in by hand.
             * This is the only figure in the entire system that comes from
             * outside it, which is the whole point of the exercise.
             */
            $table->bigInteger('saldo_rekening_rupiah');

            /*
             * Frozen at finalisation, not recomputed afterwards. A
             * reconciliation is a statement about a moment: "on 31 August the
             * books said this, the bank said that, and here is why they
             * differ". Recomputing it later against a ledger that has moved on
             * would quietly rewrite history — and a back-dated entry, which
             * the period close exists to prevent, would do exactly that.
             */
            $table->bigInteger('saldo_buku_rupiah')->default(0);
            $table->bigInteger('setoran_beredar_rupiah')->default(0);
            $table->bigInteger('penarikan_beredar_rupiah')->default(0);
            $table->bigInteger('selisih_rupiah')->default(0);

            // draft | selesai
            $table->string('status', 20)->default('draft');

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('finalised_by')->nullable()->constrained('users');
            $table->timestamp('finalised_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'tanggal_rekening']);
        });

        /*
         * One tick. The line appeared on the statement.
         *
         * A separate table rather than a column on `journal_lines`, because a
         * posted journal line is immutable — it has no `updated_at` and that is
         * deliberate. Whether the bank has seen a line is not a fact about the
         * entry; it is a fact about the bank, discovered weeks later, and it
         * belongs beside the reconciliation that discovered it.
         *
         * Unique on the line: a line ticked once can never be ticked again, so
         * next month's reconciliation cannot claim it a second time. A line
         * left unticked simply has no row here, which is what makes an
         * outstanding cheque carry forward without anybody tracking it.
         */
        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();
            $table->foreignId('journal_line_id')->unique()->constrained('journal_lines');
            $table->timestamp('created_at')->nullable();
        });

        /*
         * Something on the statement the books had never heard of.
         *
         * Bank charges, interest, a standing order, a customer's transfer
         * nobody matched. Each one is a transaction that genuinely happened —
         * the bank has already done it — so it posts its own journal entry
         * immediately rather than waiting for the reconciliation to be
         * finalised, and it ticks itself, because by definition it is on the
         * statement.
         *
         * This is the half of reconciliation that actually changes the books.
         * Without it the exercise finds a difference of Rp 15,000 and leaves
         * somebody to go and post a journal by hand, which is how a
         * reconciliation ends up abandoned three months in.
         */
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();

            $table->date('tanggal');
            $table->string('keterangan');

            // The other side. Beban Operasional for a charge, Pendapatan Lain
            // for interest, Piutang Usaha for a customer transfer, and so on.
            $table->foreignId('account_id')->constrained('accounts');

            // masuk | keluar, from the bank account's point of view.
            $table->string('arah', 10);
            $table->bigInteger('amount_rupiah');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index('bank_reconciliation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
