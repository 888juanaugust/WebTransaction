<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The thread between an order and the siblings it split into.
 *
 * When approval finds the goods scattered across warehouses, the order
 * breaks into one transaction per warehouse — each in that warehouse's
 * region and books. The original keeps its number and its home warehouse's
 * share; the siblings point back here, so "what did the customer actually
 * ask for" stays one query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('split_parent_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->index('split_parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('split_parent_id');
        });
    }
};
