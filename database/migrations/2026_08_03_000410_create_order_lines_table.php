<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('sku', 60);
            $table->unsignedSmallInteger('urutan')->default(1);

            // Unit of measure is modeled, not assumed. Both the unit the buyer
            // ordered in and the resolved base quantity are stored.
            $table->string('ordered_unit', 10);          // PCS | SET | CTN
            $table->bigInteger('ordered_qty');
            $table->unsignedInteger('qty_per_ctn_snapshot')->default(1);
            $table->string('satuan_dasar_snapshot', 10)->default('PCS');
            $table->bigInteger('qty_base');               // what the ledger counts

            // --- Price snapshot, written at `confirmed`. -------------------
            // Historical orders and invoices render from these columns and
            // never join to the live price list.
            $table->decimal('unit_price_rupiah', 18, 4)->nullable();
            $table->bigInteger('discount_rupiah')->default(0);
            $table->bigInteger('line_total_rupiah')->nullable();
            $table->bigInteger('dpp_rupiah')->nullable();
            $table->bigInteger('ppn_rupiah')->nullable();
            $table->foreignId('price_list_version_id')->nullable()->constrained('price_list_versions');

            // Why resolvePrice() returned what it returned.
            $table->string('price_reason', 40)->nullable();
            $table->jsonb('price_reason_meta')->nullable();
            $table->timestamp('priced_at')->nullable();

            // Product description snapshot, so an old order still reads right
            // after the catalogue is edited.
            $table->string('merk_snapshot', 40)->nullable();
            $table->text('description_snapshot')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'urutan']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
