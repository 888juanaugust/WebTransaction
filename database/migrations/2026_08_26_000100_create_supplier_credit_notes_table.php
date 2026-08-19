<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota kredit pemasok — the supplier owes us less, and no goods moved.
 *
 * This completes a chain that already half-worked. The three-way match detects
 * a supplier billing more than the goods were received at and flags it in red;
 * until now the only instrument for resolving it was a purchase return, which
 * takes stock off the shelf. So a pure price dispute — they agreed the price
 * was wrong, the cartons stay — could only be settled by pretending goods went
 * back, or by leaving the payable overstated until somebody netted it off a
 * later payment and nobody could explain the figure afterwards.
 *
 * The distinction against a purchase return is the whole design:
 *
 *   Retur pembelian   goods leave      stock moves, cost moves, payable falls
 *   Nota kredit       nothing leaves   payable falls, and that is all
 *
 * The credit side is chosen rather than assumed, because where the overcharge
 * originally landed depends on how it arrived. A price variance on a billed
 * receipt went to Selisih Harga Pembelian, so crediting that account unwinds
 * exactly what the bill did. A freight charge overbilled went to Biaya
 * Perolehan Belum Dialokasikan. Guessing would be right most of the time,
 * which is worse than asking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers');

            /*
             * Optional. Most credit notes correct a specific bill and naming it
             * is what makes the pair readable a year later — but a supplier can
             * also issue a blanket rebate against a quarter's trading, which
             * belongs to no single bill. Forcing one to be picked would mean
             * somebody picking the wrong one.
             */
            $table->foreignId('supplier_bill_id')->nullable()->constrained('supplier_bills');

            $table->date('tanggal');

            // Theirs, not ours: unlike a nota retur, this document is issued by
            // the supplier and we are recording what they sent.
            $table->string('nomor_nota_supplier', 60)->nullable();

            /*
             * The account the credit unwinds. Constrained to accounts and
             * additionally checked by the poster: it must not be Utang Usaha
             * (that is the other side) and must not be Persediaan, because
             * stock value only ever moves through the stock ledger.
             */
            $table->foreignId('account_id')->constrained('accounts');

            $table->bigInteger('dasar_rupiah');

            /*
             * PPN, only where the supplier issued a faktur pajak retur. Without
             * one there is nothing to take back off PPN Masukan — the input tax
             * was never credited in the first place, it went to expense.
             */
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->boolean('ada_faktur_pajak_retur')->default(false);

            $table->bigInteger('total_rupiah');

            $table->string('alasan');
            $table->text('catatan')->nullable();

            // draft | posted
            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_notes');
    }
};
