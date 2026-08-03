<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // NULL actor = the system.
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('actor_role', 20)->nullable();

            // e.g. price_override, credit_limit_override, payment_confirmed,
            // price_list_published, order_confirmed.
            $table->string('action', 60);

            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();

            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();

            $table->text('alasan')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
