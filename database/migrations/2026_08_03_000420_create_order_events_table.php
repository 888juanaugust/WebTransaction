<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // NULL from_status means the order was created at to_status.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            // NULL actor = the system (a scheduled job, or the payment webhook).
            $table->foreignId('actor_id')->nullable()->constrained('users');

            $table->text('alasan')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
