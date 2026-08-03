<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60);
            $table->foreignId('warehouse_id')->constrained('warehouses');

            // Signed, always in base units. Negative decrements.
            // This table is append-only: never UPDATE, never DELETE.
            // To undo a movement, insert the opposite one.
            $table->bigInteger('qty_signed');

            // penerimaan | pengiriman | koreksi | retur | opname | transfer_masuk | transfer_keluar
            $table->string('reason', 30);

            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 50)->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->text('catatan')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['sku', 'warehouse_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
