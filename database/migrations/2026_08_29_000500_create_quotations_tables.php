<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penawaran — the quote CLAUDE.md always listed among resolvePrice's
 * callers, built at last. Lines snapshot their prices at issue, because a
 * quote is a statement about numbers on a date; the order it may become
 * snapshots again at confirmed, per the invariant that has held since the
 * first migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 40)->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('status', 20)->default('draft');
            $table->date('valid_until');
            $table->bigInteger('subtotal_rupiah')->default(0);
            $table->bigInteger('discount_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->bigInteger('total_rupiah')->default(0);
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            // The order this quote became, once and at most once.
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->foreignId('region_id')->constrained('regions');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('region_id');
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->smallInteger('urutan');
            $table->string('sku', 60);
            $table->string('ordered_unit', 8);
            $table->integer('ordered_qty');
            $table->integer('qty_base');
            $table->integer('qty_per_ctn_snapshot');
            $table->string('satuan_dasar_snapshot', 8);
            $table->bigInteger('unit_price_rupiah');
            $table->bigInteger('discount_rupiah')->default(0);
            $table->bigInteger('line_total_rupiah');
            $table->bigInteger('dpp_rupiah');
            $table->bigInteger('ppn_rupiah');
            $table->string('price_reason', 40)->nullable();
            $table->string('merk_snapshot', 60)->nullable();
            $table->string('description_snapshot')->nullable();
            $table->timestamps();

            $table->index(['quotation_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
