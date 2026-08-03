<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            /*
             * Separates "a worker has picked this up" from "the work is done
             * and committed".
             *
             * Previously the job marked processed_at up front to claim the
             * event. A worker killed between the claim and the payment insert
             * — OOM, SIGKILL, a deploy restarting workers — left the event
             * looking processed with no money posted, and the UNIQUE
             * constraint meant Xendit's redelivery could not rescue it. The
             * customer's transfer vanished silently.
             *
             * Now the claim is claimed_at, and processed_at is written inside
             * the same transaction as the payment entry. A crash rolls both
             * back together; the sweep job re-dispatches anything left claimed
             * but unprocessed.
             */
            $table->timestamp('claimed_at')->nullable()->after('received_at');
        });

        // Finding stuck claims must not scan the whole table.
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->index(['processed_at', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex(['processed_at', 'claimed_at']);
            $table->dropColumn('claimed_at');
        });
    }
};
