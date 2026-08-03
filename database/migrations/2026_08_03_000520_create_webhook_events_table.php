<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30)->default('xendit');

            // The gateway's own event id. This UNIQUE constraint *is* the
            // idempotency mechanism: a redelivered callback collides here and
            // is never processed twice.
            $table->string('event_id', 120);

            $table->string('event_type', 60)->nullable();

            // The payload exactly as received, before anything interprets it.
            $table->jsonb('payload');

            $table->boolean('signature_verified')->default(false);
            $table->timestamp('received_at')->useCurrent();

            // Set by the queue job, not by the controller. The controller's
            // only job is to store the row and return 200.
            $table->timestamp('processed_at')->nullable();
            $table->text('process_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->unique(['gateway', 'event_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
