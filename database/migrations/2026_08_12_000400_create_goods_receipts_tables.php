<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods receipt — the document that lets stock arrive.
 *
 * Until now the only MovementReason the application ever wrote was
 * `Pengiriman`. Stock could only fall. A warehouse would drain to zero and
 * orders would start failing the availability check, which makes Phase 1 —
 * "staff enter real orders" — impossible to actually run.
 *
 * Two states, and the second is terminal:
 *
 *   draft   nothing has happened; edit freely
 *   posted  movements are in the ledger and the average cost has moved
 *
 * A posted receipt is never edited or deleted, for the same reason a stock
 * movement is not: it is the evidence behind a number on a balance sheet.
 * A mistake is corrected with an opposing document, not by rewriting this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('warehouses');

            // draft | posted
            $table->string('status', 20)->default('draft');

            // The supplier's own paperwork, so a receipt can be tied back to
            // the invoice it was entered from during a stock take or an audit.
            $table->string('nomor_surat_jalan_supplier', 60)->nullable();
            $table->string('nomor_faktur_supplier', 60)->nullable();
            $table->date('tanggal_terima');

            // Summed from the lines when posted. BIGINT rupiah, never float.
            $table->bigInteger('total_value_rupiah')->default(0);

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'tanggal_terima']);
            $table->index('supplier_id');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->string('sku', 60);
            $table->unsignedInteger('urutan')->default(0);

            /*
             * Unit of measure is modelled, not assumed — the same rule the
             * order lines follow. A supplier invoices in cartons; the stock
             * ledger is always in base units; both are stored so neither has
             * to be reverse-engineered later.
             */
            $table->string('ordered_unit', 10);
            $table->integer('ordered_qty');
            $table->integer('qty_per_ctn_snapshot')->nullable();
            $table->string('satuan_dasar_snapshot', 10)->nullable();
            $table->integer('qty_base');

            /*
             * Cost per *received* unit — per carton if received in cartons —
             * because that is the figure printed on the supplier's invoice and
             * the one a person can check. The per-base-unit cost is derived by
             * dividing the line value by qty_base, so it never has to be
             * rounded into storage.
             */
            $table->bigInteger('unit_cost_rupiah');
            $table->bigInteger('line_value_rupiah');

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index(['goods_receipt_id', 'urutan']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
