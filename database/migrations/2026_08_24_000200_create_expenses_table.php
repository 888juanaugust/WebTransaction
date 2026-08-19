<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beban — money going out that is not paying a supplier for goods.
 *
 * Rent, salaries, electricity, fuel, the courier, the accountant's fee. Until
 * this table there was no way to record any of it: `JournalDraft::manual()`
 * existed and nothing in the application ever called it, so the only expense
 * that could reach the ledger was a bank charge found during a reconciliation.
 *
 * A document rather than a raw journal entry, for the same reasons every other
 * money movement here is one. It gets a number somebody can quote, it can be
 * found again, it carries who recorded it, and — because `Ledger::post()` is
 * idempotent on (source_type, source_id, jenis) — recording the same expense
 * twice by double-clicking cannot post it twice.
 *
 * **Posted on creation, never edited.** There is no draft stage: an expense is
 * recorded after the money has already gone, exactly like a bank statement
 * item, and a draft would only be a way for real spending to sit unrecorded.
 * Getting one wrong is fixed by reversing it, which is how every other ledger
 * document in this system behaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->date('tanggal');

            /*
             * The expense account. Constrained to accounts, and the recorder
             * additionally refuses anything that is not an operating expense —
             * debiting Harga Pokok Penjualan by hand would put a number into
             * gross margin that no stock movement backs, and the inventory tie
             * would stop meaning anything.
             */
            $table->foreignId('account_id')->constrained('accounts');

            // kas | bank — which asset the money actually left.
            $table->string('dibayar_dari', 10);

            $table->bigInteger('amount_rupiah');

            $table->string('keterangan');

            /*
             * Optional. A landlord or the electricity company is not a supplier
             * in the goods sense and has no bills in this system, but naming
             * one when it applies makes the ledger line traceable.
             */
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');

            // Whose receipt this is: a number off a bill, a reference.
            $table->string('referensi', 60)->nullable();
            $table->text('catatan')->nullable();

            /*
             * A reversal points at what it reverses. Nothing is ever edited or
             * deleted, so a mistake leaves two rows that cancel and both stay
             * visible — which is the record an auditor wants to see anyway.
             */
            $table->foreignId('reverses_expense_id')->nullable()->constrained('expenses');

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['tanggal', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
