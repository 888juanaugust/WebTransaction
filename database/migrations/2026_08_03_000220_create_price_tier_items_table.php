<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tier_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_tier_id')->constrained('price_tiers')->cascadeOnDelete();

            // NULL kode = applies to every SKU in the tier at this quantity break.
            $table->string('kode', 60)->nullable();

            // Quantity break, expressed in base units. The highest min_qty_base
            // that the ordered quantity reaches wins.
            $table->bigInteger('min_qty_base')->default(1);

            // Exactly one of these is set. harga is an absolute rupiah price;
            // discount_bps is taken off the list price.
            $table->bigInteger('harga')->nullable();
            $table->integer('discount_bps')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['price_tier_id', 'kode', 'min_qty_base'], 'price_tier_items_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_tier_items');
    }
};
