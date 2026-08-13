<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bills — what we owe, and the PPN we can credit.
 *
 * The mirror of `invoices`, and deliberately the same shape: figures are summed
 * from line snapshots, the total is fixed when the bill is posted, and nothing
 * afterwards may edit it. Corrections are a new document, not a rewrite.
 *
 * The tax side is not decoration. PPN on a purchase is **pajak masukan** —
 * input VAT we can credit against the output VAT on our sales — so it is real
 * money, and it has to be recorded per line for the same reason the faktur
 * does: summing rounded lines is not the same number as rounding a summed
 * total, and the difference lands on a tax return.
 *
 * Payment against a bill is an append-only ledger, exactly as customer payments
 * are. A paid row is never mutated; a mistake is a reversing entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Payment terms live on the supplier so a bill's due date is derived
         * rather than typed — the same way a customer invoice's due date comes
         * from the company's agreed terms.
         */
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->default(30)->after('npwp');
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();

            // Our own reference. The supplier's invoice number is theirs and
            // cannot be trusted to be unique across suppliers.
            $table->string('nomor', 30)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders');

            // Their paperwork. `nomor_faktur_pajak` is the NSFP on their faktur
            // pajak, which is what makes the input VAT creditable.
            $table->string('nomor_faktur_supplier', 60);
            $table->string('nomor_faktur_pajak', 30)->nullable();

            $table->date('tanggal_faktur');
            $table->date('due_date');

            // open | paid | void — the same three the customer side uses.
            $table->string('status', 20)->default('open');

            // Summed from the line snapshots. BIGINT rupiah.
            $table->bigInteger('subtotal_rupiah')->default(0);
            $table->bigInteger('discount_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->bigInteger('total_rupiah')->default(0);

            // 04 for ordinary goods on the DPP nilai lain, as on the sell side.
            $table->string('kode_transaksi', 2)->default('04');

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('supplier_id');
            $table->index('nomor_faktur_supplier');
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();

            /*
             * What this line is billing for. Nullable because a supplier can
             * bill for things that never touched stock — freight, handling —
             * and refusing to record those would send them somewhere untracked.
             */
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines');

            $table->string('sku', 60)->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->string('deskripsi')->nullable();

            $table->integer('qty_base')->default(0);
            $table->bigInteger('unit_cost_rupiah')->default(0);
            $table->bigInteger('line_total_rupiah');

            // Per line, never only on the total.
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);

            $table->timestamps();

            $table->index(['supplier_bill_id', 'urutan']);
        });

        /*
         * Money out, append-only, mirroring payment_entries.
         *
         * Positive means we paid the supplier. A row is never mutated; undoing
         * one means inserting a reversing entry that points back at it.
         */
        Schema::create('supplier_payment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers');

            // NULL while a payment is recorded but not yet matched to a bill.
            $table->foreignId('supplier_bill_id')->nullable()->constrained('supplier_bills');

            $table->bigInteger('amount_rupiah');

            // payment | reversal | adjustment
            $table->string('kind', 20)->default('payment');

            $table->string('referensi', 120)->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('supplier_payment_entries');

            $table->timestamp('paid_at')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_id', 'supplier_bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_entries');
        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_bills');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('payment_terms_days');
        });
    }
};
