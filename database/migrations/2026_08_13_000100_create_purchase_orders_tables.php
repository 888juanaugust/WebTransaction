<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders — what we asked a supplier for.
 *
 * The goods receipt records what turned up. Without a purchase order there is
 * nothing to compare that against: a short delivery looks identical to a
 * complete one, and a price the supplier quietly changed between quote and
 * invoice is invisible.
 *
 *   draft → dikirim → selesai
 *              ↓
 *         dibatalkan
 *
 * `dikirim` means the order has gone to the supplier. It locks the lines,
 * because from that point the document is a record of what was agreed, not a
 * working note — and receiving against a line somebody edited afterwards would
 * compare deliveries against a moving target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('supplier_id')->constrained('suppliers');
            // Where the goods are expected. A receipt may land elsewhere, and
            // the match still holds — this is an expectation, not a constraint.
            $table->foreignId('warehouse_id')->constrained('warehouses');

            // draft | dikirim | selesai | dibatalkan
            $table->string('status', 20)->default('draft');

            $table->date('tanggal_po');
            $table->date('tanggal_diharapkan')->nullable();

            // The supplier's own reference for this order, if they give one.
            $table->string('referensi_supplier', 60)->nullable();

            // Summed from the lines. BIGINT rupiah, never float.
            $table->bigInteger('total_value_rupiah')->default(0);

            $table->text('catatan')->nullable();
            $table->text('alasan_batal')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'tanggal_po']);
            $table->index('supplier_id');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('sku', 60);
            $table->unsignedInteger('urutan')->default(0);

            // Unit of measure modelled, not assumed — the same rule the order
            // lines and the receipt lines follow.
            $table->string('ordered_unit', 10);
            $table->integer('ordered_qty');
            $table->integer('qty_per_ctn_snapshot')->nullable();
            $table->string('satuan_dasar_snapshot', 10)->nullable();
            $table->integer('qty_base');

            // Agreed cost per *ordered* unit — per carton if ordered by the
            // carton, matching how the supplier quotes.
            $table->bigInteger('unit_cost_rupiah');
            $table->bigInteger('line_value_rupiah');

            /*
             * Cached: base units received against this line so far.
             *
             * Reconstructible by summing goods_receipt_lines that point here,
             * the same relationship stock_levels has to stock_movements. The
             * receipts are the truth; this is what makes "what is still
             * outstanding" a query rather than a join per row.
             */
            $table->integer('qty_base_received')->default(0);

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'urutan']);
            $table->index('sku');
        });

        /*
         * A receipt may or may not have a purchase order behind it. Stock
         * sometimes simply turns up — an urgent top-up bought over the counter,
         * or the opening balance when the system goes live — and refusing to
         * record that would push people back to recording it nowhere.
         */
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')
                ->constrained('purchase_orders');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')->nullable()->after('sku')
                ->constrained('purchase_order_lines');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
