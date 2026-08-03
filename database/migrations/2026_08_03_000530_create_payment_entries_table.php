<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');

            // NULL while the payment is received but not yet matched to an
            // invoice — that is the "unmatched payments" admin queue.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('order_id')->nullable()->constrained('orders');

            // Signed BIGINT rupiah. Positive credits the customer's balance.
            // This table is append-only: a payment row is never mutated. To
            // undo one, insert a reversing entry.
            $table->bigInteger('amount_rupiah');

            // payment | reversal | adjustment | writeoff
            $table->string('kind', 20)->default('payment');

            $table->string('gateway', 30)->nullable();
            $table->string('gateway_reference', 120)->nullable();

            // Provenance: which callback produced this entry.
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events');

            // Set for manual entries only. A gateway payment has no actor.
            $table->foreignId('actor_id')->nullable()->constrained('users');

            // Points at the entry this one reverses.
            $table->foreignId('reverses_entry_id')->nullable()->constrained('payment_entries');

            $table->timestamp('paid_at')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'invoice_id']);
            $table->index('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_entries');
    }
};
