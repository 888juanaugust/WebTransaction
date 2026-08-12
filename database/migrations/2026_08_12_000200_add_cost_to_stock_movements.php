<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Put cost on the movement itself.
 *
 * This is the column that makes COGS, gross margin and inventory value
 * possible, and it is the one thing on this whole system that cannot be
 * retrofitted honestly. A movement records what happened at a moment; if the
 * cost that applied at that moment was never written down, no later migration
 * can recover it — you would be guessing at history and calling the guess an
 * accounting record.
 *
 * `value_rupiah` is signed and follows qty_signed: positive on a receipt,
 * negative on a shipment. Summing it over a period *is* the movement of
 * inventory value; summing it over Pengiriman rows *is* COGS.
 *
 * Both are nullable rather than defaulted to zero, because rows written before
 * this migration genuinely have no known cost, and zero would silently assert
 * that stock was free. InventoryValuation counts unvalued movements and reports
 * them rather than averaging them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Cost per base unit applied to this movement, in whole rupiah.
            $table->bigInteger('unit_cost_rupiah')->nullable()->after('qty_signed');

            // qty_signed × unit cost. Stored rather than derived so that a
            // sum() over the ledger is the value movement, with no rounding
            // reapplied per row at read time.
            $table->bigInteger('value_rupiah')->nullable()->after('unit_cost_rupiah');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost_rupiah', 'value_rupiah']);
        });
    }
};
