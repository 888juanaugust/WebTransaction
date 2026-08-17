<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landed cost — the freight and duty that belong in the price of the goods.
 *
 * A carton does not cost what the supplier invoiced for it. It costs that plus
 * the shipping, the customs duty, the handling at the port and whatever else
 * had to be paid to get it onto our shelf. Leave those out and every margin
 * figure in the system is optimistic by exactly the amount we forgot.
 *
 * The awkward part is timing. The forwarder's invoice arrives days or weeks
 * after the goods, by which point some of that shipment has already been sold.
 * Three ways to handle that, and only one of them is honest here:
 *
 *  - restate the shipments that already went out. Refused: CLAUDE.md freezes
 *    cost at the movement, and last month's gross margin changing because a
 *    freight bill turned up today is precisely what that rule exists to stop.
 *  - put the whole charge on what is left. Refused: if nine tenths of the
 *    shipment is gone, the remaining tenth absorbs ten times its share and
 *    every later sale of it looks like a loss.
 *  - split it. The share belonging to goods still on the shelf raises their
 *    cost; the share belonging to goods already sold is a cost of this period
 *    and goes straight to HPP. Nothing historical is rewritten, and no rupiah
 *    is lost. That is what this does.
 *
 * Two states, and the second is terminal, like every other money document
 * here. A wrong allocation is corrected by another one, not by an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What a bill line is billing for.
         *
         * `barang` is goods — it clears Utang Belum Ditagih against a receipt,
         * which is what every existing line does, hence the default.
         *
         * `biaya` is a service charge with no goods behind it. Until now those
         * were recorded with a null goods_receipt_line_id and posted to Utang
         * Belum Ditagih anyway, which left a permanent contra balance in an
         * account whose whole purpose is to be empty. They now go to the
         * clearing account instead and wait to be allocated.
         */
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->string('jenis', 10)->default('barang')->after('goods_receipt_line_id');
            $table->index(['jenis']);
        });

        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            /*
             * One allocation per charge, enforced here rather than in code.
             * Spreading the same freight invoice twice would double-count it
             * into inventory, and the clearing account would go negative —
             * visible, but only to somebody reading the balance sheet.
             */
            $table->foreignId('supplier_bill_line_id')->unique()->constrained('supplier_bill_lines');

            $table->date('tanggal');

            // nilai | kuantitas — see AllocationBasis for why both exist.
            $table->string('dasar', 20);

            // The charge being spread. Copied from the bill line at draw time
            // so the document stands on its own, and re-checked at posting.
            $table->bigInteger('amount_rupiah');

            // draft | posted
            $table->string('status', 20)->default('draft');

            /*
             * How the charge actually split when it was posted. Recomputing
             * these later would give a different answer, because the answer
             * depends on how much was still on the shelf at that moment.
             */
            $table->bigInteger('ke_persediaan_rupiah')->default(0);
            $table->bigInteger('ke_hpp_rupiah')->default(0);

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'tanggal']);
        });

        Schema::create('landed_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('landed_costs')->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines');

            $table->string('sku', 60);
            $table->unsignedInteger('urutan')->default(0);
            $table->foreignId('warehouse_id')->constrained('warehouses');

            /*
             * The weight this line carried in the split, and what it was
             * measured in. Snapshotted because the receipt line it came from
             * is immutable but the *basis* is a choice, and a person looking
             * at this later needs to see the arithmetic without redoing it.
             */
            $table->bigInteger('dasar_nilai');
            $table->integer('qty_base');

            $table->bigInteger('amount_rupiah')->default(0);

            // Filled at posting. qty_on_hand is what was still on the shelf
            // for this SKU, which is what decided the split.
            $table->integer('qty_on_hand')->nullable();
            $table->bigInteger('ke_persediaan_rupiah')->default(0);
            $table->bigInteger('ke_hpp_rupiah')->default(0);

            $table->timestamps();

            $table->index(['landed_cost_id', 'urutan']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_lines');
        Schema::dropIfExists('landed_costs');

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropIndex(['jenis']);
            $table->dropColumn('jenis');
        });
    }
};
