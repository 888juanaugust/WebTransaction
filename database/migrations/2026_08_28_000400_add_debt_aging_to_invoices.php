<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the aging notice for this invoice went out, if it has.
 *
 * A marker rather than derived state, because "have we already told them" is
 * a fact about what happened, not about the calendar. Deriving it would send
 * the same reminder every night; storing it sends it once, and NULL means the
 * invoice has not aged that far or was paid before it did.
 *
 * The freeze needs no column: fall-due is pure calendar arithmetic over open
 * invoices, and derived state cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('debt_notified_at')->nullable()->after('faktur_exported_at');
        });

        // The standard Laravel notifications table, so the aging notices land
        // in the panel's bell for the team in charge rather than in a log
        // nobody reads. First feature to need it, hence created here.
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                // json, not text: Filament's bell filters on data->>'format',
                // which Postgres only allows on json columns.
                $table->json('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('debt_notified_at');
        });
    }
};
