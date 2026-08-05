<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until now every order was placed by staff, so `created_by` pointing at
 * `users` was the whole story. A buyer placing their own order in the portal is
 * not a staff user and must not be recorded as one — "who placed this" is a
 * question the AR conversation eventually turns on.
 *
 * Additive, as the conventions require: both columns are nullable and nothing
 * existing changes meaning. An order with neither was placed by staff before
 * the portal existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Set means the buyer placed this themselves in the portal.
            // `created_by` stays null for those — there was no staff user.
            $table->foreignId('placed_by_customer_user_id')
                ->nullable()
                ->after('sales_user_id')
                ->constrained('customer_users');
        });

        Schema::table('order_events', function (Blueprint $table) {
            // The event log already allows a null actor to mean "the system".
            // A buyer is a third kind of actor, not a missing one, so it gets
            // its own column rather than overloading actor_id.
            $table->foreignId('customer_actor_id')
                ->nullable()
                ->after('actor_id')
                ->constrained('customer_users');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_actor_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placed_by_customer_user_id');
        });
    }
};
