<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60);
            $table->foreignId('warehouse_id')->constrained('warehouses');

            // Cached aggregate of stock_movements. Updated inside the same
            // transaction as the movement, and always reconstructible by
            // summing the ledger — see StockLedger::reconcile().
            // Always in base units.
            $table->bigInteger('qty_on_hand')->default(0);

            // Held by confirmed-but-not-yet-shipped orders. Not a ledger
            // quantity: reservations do not move stock, they fence it.
            $table->bigInteger('qty_reserved')->default(0);

            $table->timestamps();

            $table->unique(['sku', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
