<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment gateway is gone — settlement is the payment ledger alone.
 *
 * The business sells on credit and is paid by transfer, cash or giro, all of
 * them confirmed by finance against the bank statement. The Xendit virtual
 * accounts and their webhook inbox never carried a production rupiah, so the
 * tables go rather than lingering as a trap for a future reader who assumes
 * a gateway still answers.
 *
 * payment_entries loses the three columns only the gateway wrote:
 * `gateway`, `gateway_reference` and the FK into webhook_events. Every other
 * column — and every rule about the ledger being append-only — stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webhook_event_id');
            $table->dropColumn(['gateway', 'gateway_reference']);
        });

        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('virtual_accounts');
    }

    public function down(): void
    {
        /*
         * Structure only — the rows are gone for good. A real reversal is not
         * expected in production; this exists so `migrate:rollback` can walk
         * past this migration to the ones beneath it, whose down() methods
         * touch these tables. The shapes mirror the original create
         * migrations plus what add_region_to_scoped_tables added.
         */
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30)->default('xendit');
            $table->string('event_id', 120);
            $table->string('event_type', 60)->nullable();
            $table->jsonb('payload');
            $table->boolean('signature_verified')->default(false);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('process_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->unique(['gateway', 'event_id']);
            $table->index('processed_at');
            $table->index(['processed_at', 'claimed_at']);
        });

        Schema::create('virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions');
            $table->foreignId('company_id')->constrained('companies');
            $table->string('bank_code', 20);
            $table->string('account_number', 40);
            $table->string('external_id', 100)->nullable();
            $table->string('gateway_id', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'bank_code']);
            $table->unique('account_number');
            $table->index('region_id', 'virtual_accounts_region_id_index');
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_reference', 120)->nullable();
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events');

            $table->index('gateway_reference');
        });
    }
};
