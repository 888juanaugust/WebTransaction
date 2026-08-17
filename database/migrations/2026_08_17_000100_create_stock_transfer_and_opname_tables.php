<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two stock documents that were missing.
 *
 * `MovementReason` has carried Transfer and Opname since the ledger was
 * written, and nothing could produce either. Staff moving a carton between
 * warehouses, or finding the shelf short after a count, had no document to
 * record it with — so it either did not get recorded, or it got recorded as
 * something it was not.
 *
 * **Transfer** pairs an out with an in. It is value-neutral by construction:
 * `product_costs` is keyed by SKU rather than by warehouse, so moving goods
 * between two of our own buildings changes nothing about what they are worth.
 *
 * **Opname** is a count, and the interesting part is the variance. A shelf
 * short of what the system says is a loss that has to land somewhere, and the
 * person who counted should not be the person who signs it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');

            $table->date('tanggal');
            $table->text('catatan')->nullable();

            $table->string('status', 20)->default('draft');

            /*
             * Cached from the movements at posting. Not a control — a transfer
             * cannot change the total — but the figure a warehouse manager
             * wants when asked what is in the van.
             */
            $table->bigInteger('total_value_rupiah')->default(0);

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'tanggal']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();

            $table->string('sku', 50);
            $table->unsignedInteger('urutan')->default(0);

            // Both units, same as everywhere else: staff talk in cartons and
            // the ledger counts pieces.
            $table->string('ordered_unit', 8)->nullable();
            $table->integer('ordered_qty')->default(0);
            $table->integer('qty_per_ctn_snapshot')->default(1);
            $table->integer('qty_base');

            // What the goods were carrying when they moved. Recorded for the
            // document; the valuation is unchanged either way.
            $table->bigInteger('unit_cost_rupiah')->nullable();
            $table->bigInteger('line_value_rupiah')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['stock_transfer_id', 'urutan']);
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('tanggal');
            $table->text('catatan')->nullable();

            // draft | posted
            $table->string('status', 20)->default('draft');

            /*
             * The variance, cached at posting. Quantity and money are stored
             * separately because they answer different questions: how many
             * pieces walked, and what that cost.
             */
            $table->integer('selisih_qty')->default(0);
            $table->bigInteger('selisih_rupiah')->default(0);

            /*
             * Counted by one person, approved by another. That separation is
             * the whole control on a document whose purpose is to write stock
             * off, so both are recorded and the poster refuses to let one
             * person be both.
             */
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('counted_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'tanggal']);
        });

        Schema::create('stock_opname_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->cascadeOnDelete();

            $table->string('sku', 50);
            $table->unsignedInteger('urutan')->default(0);

            /*
             * What the system said when the sheet was drawn. Snapshotted so
             * the counter is comparing against a stated number rather than a
             * moving one — and so posting can tell whether stock moved during
             * the count.
             */
            $table->integer('qty_system');

            // What was actually on the shelf. Null until somebody counts it.
            $table->integer('qty_counted')->nullable();

            // Filled at posting, from the movement the adjustment produced.
            $table->integer('selisih_qty')->default(0);
            $table->bigInteger('unit_cost_rupiah')->nullable();
            $table->bigInteger('selisih_rupiah')->default(0);

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_id', 'sku']);
            $table->index(['stock_opname_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_lines');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
