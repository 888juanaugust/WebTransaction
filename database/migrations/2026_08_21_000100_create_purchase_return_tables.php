<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur pembelian — goods going back to a supplier.
 *
 * The mirror of the nota kredit, and the last document in the purchase chain
 * with no way to record it. Wrong part, damaged carton, a shipment nobody
 * ordered: until now the only options were to keep it on the shelf and pay for
 * it, or to write the stock off as a count variance and quietly overstate what
 * we owe.
 *
 * A return is always **against a goods receipt**, never floating. The receipt
 * is the only evidence that the goods ever arrived and the only record of what
 * they cost — you cannot send back what never came, and you cannot credit
 * yourself for it at a price nobody agreed.
 *
 * The part that makes this document more than a reversed receipt is *when* it
 * happens relative to the bill:
 *
 *   - **Not yet billed.** The receipt accrued Utang Belum Ditagih; the return
 *     unwinds exactly that. No tax is involved, because there is no faktur
 *     pajak yet.
 *   - **Already billed.** The accrual is long cleared and a real debt stands
 *     in its place, so the return reduces Utang Usaha and reverses the input
 *     VAT that came with it.
 *
 * Both happen, often on the same delivery, and getting the split wrong breaks
 * a control account rather than merely looking untidy. It is computed per line
 * rather than asked of whoever is typing — see PurchaseReturnPoster.
 *
 * Under the PPN rules this document *is* the nota retur: the buyer issues it to
 * the seller, and the buyer reduces its own PPN masukan in the period it is
 * issued. So its own `nomor` is the reference, and what the supplier sends back
 * afterwards is recorded beside it. Confirm the Coretax treatment with the
 * accountant before the first real one — see docs/MAP.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers');

            /*
             * The delivery being sent back, in part or in whole. One receipt
             * per return: a return spanning two deliveries is two returns, and
             * keeping it that way is what lets every line's cost be read off
             * one document rather than reconstructed.
             */
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts');

            // Where the goods leave from. The receipt's warehouse — they go
            // back out of the door they came in.
            $table->foreignId('warehouse_id')->constrained('warehouses');

            $table->date('tanggal');

            /*
             * Mandatory, same as a nota kredit. This document writes stock off
             * the shelf and reduces a debt, and "why" is the first question
             * anybody asks of it a year later.
             */
            $table->text('alasan');

            /*
             * The supplier's own credit note, when it arrives. Ours is the
             * nota retur that starts the process; theirs is the acknowledgement
             * that closes it, and the gap between the two is the list of
             * returns nobody has chased.
             */
            $table->string('nomor_nota_kredit_supplier', 60)->nullable();

            /*
             * All settled at posting from the line snapshots, and never
             * editable afterwards — the same control the supplier bill carries.
             *
             *   nilai_ditagih        what the supplier billed for the portion
             *                        they had already billed. Reduces Utang
             *                        Usaha.
             *   nilai_belum_ditagih  what the receipt valued the portion they
             *                        had not billed. Reverses Utang Belum
             *                        Ditagih.
             *   ppn                  input VAT on the billed portion only.
             *   total                nilai_ditagih + ppn: what the supplier now
             *                        owes us, and what comes off Utang Usaha.
             */
            $table->bigInteger('nilai_ditagih_rupiah')->default(0);
            $table->bigInteger('nilai_belum_ditagih_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->bigInteger('total_rupiah')->default(0);

            /*
             * What the goods were actually carried at when they left, taken
             * from the movements rather than recomputed. Under moving-average
             * costing this need not equal what the supplier credits: stock
             * received since at a different price has moved the average.
             */
            $table->bigInteger('nilai_persediaan_rupiah')->default(0);

            /*
             * The difference between the two, which is a real gain or loss and
             * not a rounding artefact. Booked to Selisih Harga Pembelian — the
             * account that already means "what stock is carried at and what the
             * supplier settles at are not the same number".
             */
            $table->bigInteger('selisih_rupiah')->default(0);

            $table->string('kode_transaksi', 2)->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'tanggal']);
            $table->index(['goods_receipt_id', 'status']);
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')
                ->constrained('purchase_returns')
                ->cascadeOnDelete();

            /*
             * Never nullable, unlike a credit note line. There is no purchase
             * equivalent of a potongan here: a supplier knocking money off
             * without goods moving is a credit against the bill, not a return,
             * and it is not built. See docs/MAP.md.
             */
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines');

            /*
             * The bill this line credits, when the goods had been billed.
             *
             * Null means nobody had invoiced them yet, and the line unwinds the
             * accrual instead. Recorded rather than derived because it decides
             * whether a bill still gets chased: a delivery returned in full
             * before payment must stop appearing in the overdue queue, and
             * working that out by re-joining through the receipt every time is
             * how the answer drifts from the one the posting used.
             */
            $table->foreignId('supplier_bill_id')->nullable()->constrained('supplier_bills');

            $table->string('sku', 50);
            $table->unsignedInteger('urutan')->default(0);
            $table->string('deskripsi')->nullable();

            // Both units, same as everywhere else. Suppliers deal in cartons
            // and the shelf is counted in pieces.
            $table->string('ordered_unit', 8)->nullable();
            $table->integer('ordered_qty')->default(0);
            $table->integer('qty_per_ctn_snapshot')->default(1);
            $table->integer('qty_base')->default(0);

            /*
             * How much of this line's quantity the supplier had already billed.
             * Between 0 and qty_base. The rest reverses the accrual instead.
             */
            $table->integer('qty_ditagih')->default(0);

            $table->bigInteger('nilai_ditagih_rupiah')->default(0);
            $table->bigInteger('nilai_belum_ditagih_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);

            // What left the shelf, from the movement. Not recomputed.
            $table->bigInteger('unit_cost_rupiah')->default(0);
            $table->bigInteger('nilai_persediaan_rupiah')->default(0);

            $table->timestamps();

            $table->index(['purchase_return_id', 'urutan']);
            $table->index('goods_receipt_line_id');
            $table->index('supplier_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
    }
};
