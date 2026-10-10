<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The 30-day watch: once per order, what the administrators were told and when. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->cascadeOnDelete();
            $table->string('state', 12); // delivered | partial | pending
            $table->decimal('ordered_base_qty', 18, 4)->default(0);
            $table->decimal('delivered_base_qty', 18, 4)->default(0);
            $table->unsignedSmallInteger('days');
            $table->jsonb('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_notices');
    }
};
