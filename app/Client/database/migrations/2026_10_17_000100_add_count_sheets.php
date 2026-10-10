<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Count sheets on a cadence: the kind of a count, the SKUs a daily sheet asks for, and when the gudang finished counting. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_orders', function (Blueprint $table): void {
            $table->string('kind', 12)->default('manual')->index(); // manual | daily | semester
        });
        Schema::create('stock_opname_order_items', function (Blueprint $table): void {
            $table->foreignId('stock_opname_order_id')->constrained('stock_opname_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->primary(['stock_opname_order_id', 'item_id']);
        });
        Schema::table('stock_opname_results', function (Blueprint $table): void {
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_results', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('counted_by');
            $table->dropColumn('counted_at');
        });
        Schema::dropIfExists('stock_opname_order_items');
        Schema::table('stock_opname_orders', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
