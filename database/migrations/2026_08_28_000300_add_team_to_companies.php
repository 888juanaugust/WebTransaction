<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team in charge of a customer: one sales, one marketing.
 *
 * Two columns on the customer rather than a teams table. The organisation's
 * "team" is a pair of people repeatedly assigned together, and the pair
 * emerges from the assignments — a separate entity would add a join to every
 * "my customers" query and a second place for the pairing to be wrong, and
 * would still need both member columns validated the same way.
 *
 * Nullable, because customers exist before anybody is assigned to them and
 * the system has customers already. NULL reads as "belum ada tim" on the
 * screen and as a worklist entry for the admin, not as a crash.
 *
 * ON DELETE not specified → NO ACTION: a member of staff with customers
 * assigned cannot be deleted (staff are never deleted anyway — they are
 * deactivated, and TeamAssigner refuses inactive assignees going forward).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('sales_user_id')->nullable()->after('region_id')
                ->constrained('users');
            $table->foreignId('marketing_user_id')->nullable()->after('sales_user_id')
                ->constrained('users');

            // "My customers" is the query both roles run on every screen.
            $table->index('sales_user_id');
            $table->index('marketing_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_user_id');
            $table->dropConstrainedForeignId('marketing_user_id');
        });
    }
};
