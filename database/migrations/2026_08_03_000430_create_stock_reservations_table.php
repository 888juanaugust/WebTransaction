<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->string('sku', 60);
            $table->foreignId('warehouse_id')->constrained('warehouses');

            // Base units. Stock is reserved at confirmed, decremented at shipped.
            $table->bigInteger('qty_base');

            // held | released | consumed
            $table->string('status', 20)->default('held');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 50)->nullable();

            $table->index(['sku', 'warehouse_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // A line may accumulate several released reservations over its life,
        // but only ever one held at a time.
        DB::statement(
            'CREATE UNIQUE INDEX stock_reservations_one_held_per_line
             ON stock_reservations (order_line_id) WHERE status = \'held\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
