<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general ledger — the thing every subledger here has been missing.
 *
 * Stock movements, payment entries and invoices are all already append-only
 * ledgers of their own. Each is authoritative about one thing and none of them
 * can produce a balance sheet, because nothing states that the money leaving
 * inventory is the same money arriving in cost of sales. Double entry is what
 * says that, and it is the only reason a trial balance can be checked at all.
 *
 * The rules this schema exists to enforce:
 *
 *   1. A journal entry always balances. Debits equal credits, in integer
 *      rupiah, or the entry is refused. Not "warned about" — refused.
 *   2. Nothing is ever edited. A posted entry is evidence; a mistake is
 *      corrected with a reversing entry that points back at what it undoes.
 *   3. Every entry names the document that caused it, so any figure on any
 *      statement can be walked back to the order, invoice or receipt behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The chart of accounts.
         *
         * Kept deliberately small — a trading company's minimum, not a
         * template with four hundred rows nobody uses. Accounts are added when
         * a transaction needs one, which is the only way anybody ever knows
         * what an account is for.
         */
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            // Indonesian convention: 1-xxxx aset, 2-xxxx kewajiban, and so on.
            $table->string('kode', 20)->unique();
            $table->string('nama');

            // aset | kewajiban | modal | pendapatan | beban
            $table->string('tipe', 20);

            /*
             * Which side increases this account. Derived from `tipe` in
             * practice, but stored because it is what the posting rules and
             * every report read, and deriving it in six places is how one of
             * them ends up deriving it differently.
             */
            $table->string('saldo_normal', 6);

            // Headers group their children on a report and cannot be posted to.
            $table->boolean('dapat_diposting')->default(true);
            $table->foreignId('parent_id')->nullable()->constrained('accounts');

            $table->boolean('aktif')->default(true);
            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index(['tipe', 'kode']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            // The date the transaction belongs to, which is not always the date
            // it was typed — a bill entered late still belongs to its own month.
            $table->date('tanggal');

            $table->string('keterangan');

            /*
             * The document behind it. Polymorphic because everything posts:
             * invoices, receipts, bills, payments, shipments, and the manual
             * journals an accountant writes at period end.
             */
            $table->string('source_type')->nullable();
            $table->string('source_id', 60)->nullable();

            /*
             * Why this entry exists for that document. One order posts twice —
             * revenue when it is invoiced, cost when it is shipped — and those
             * are different entries about the same row, so the source alone is
             * not an identity.
             */
            $table->string('jenis', 30);

            // Cached from the lines. Both sides are stored so a mismatch is
            // visible in the row itself rather than only by summing.
            $table->bigInteger('total_debit_rupiah');
            $table->bigInteger('total_kredit_rupiah');

            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->useCurrent();

            /*
             * Reversal is the only correction there is, so it is navigable from
             * both ends: `reversed_by_entry_id` is set on the entry that was
             * undone, `reverses_entry_id` on the one that undid it. Setting the
             * first is the only write that ever touches a posted row, and the
             * unique index on it is what stops the same entry being reversed
             * twice by two people at once.
             */
            $table->foreignId('reversed_by_entry_id')->nullable()->unique()->constrained('journal_entries');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tanggal', 'id']);

            /*
             * Idempotency. Every posting is driven from a document, and a queue
             * job that runs twice — or a member of staff who clicks twice —
             * must not double the books. The database refuses the second one.
             */
            $table->unique(['source_type', 'source_id', 'jenis']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');

            /*
             * One side per line, and the other is zero. Storing a single signed
             * amount would be smaller and is how ledgers get subtly wrong: a
             * negative debit and a credit are the same number to arithmetic and
             * different things to an accountant reading the account.
             */
            $table->bigInteger('debit_rupiah')->default(0);
            $table->bigInteger('kredit_rupiah')->default(0);

            $table->string('memo')->nullable();

            // Who this line is about, where that is meaningful — an AR line
            // belongs to a customer, an AP line to a supplier. Lets a control
            // account be proved against its subledger.
            $table->foreignId('company_id')->nullable()->constrained('companies');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');

            $table->unsignedInteger('urutan')->default(0);

            $table->index(['journal_entry_id', 'urutan']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
