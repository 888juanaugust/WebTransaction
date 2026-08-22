<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways to overrule the reorder arithmetic, per SKU.
 *
 * The reorder point is derived — from how fast a part actually sells and how
 * long the supplier actually takes — and derived is right nearly always. The
 * two cases where it is wrong are both cases where the future is not going to
 * look like the past, and no amount of history can tell you that:
 *
 * - A line being **discontinued**. It sold fine for a year and we are never
 *   buying it again. The arithmetic will keep asking for more until the last
 *   one leaves the shelf.
 * - A part where somebody **knows better**. A single customer's contract, a
 *   part that is unobtainable for three months, a minimum the supplier
 *   imposes. The figure is a judgement, not a measurement.
 *
 * Both are columns on the product rather than a table of their own: there is
 * at most one of each per SKU, they are set rarely, and a separate table would
 * add a join to every reorder query to hold a number that is usually null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
             * In base units, and it wins outright when set — the derived
             * figure is not consulted at all. A blended "higher of the two"
             * would be a number nobody typed and nobody measured.
             */
            $table->integer('titik_pesan_ulang_manual')->nullable();

            // Off the worklist entirely. Not the same as `aktif = false`: a
            // discontinued line still sells down its remaining stock, still
            // appears in the catalogue, and still must not be reordered.
            $table->boolean('jangan_pesan_ulang')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['titik_pesan_ulang_manual', 'jangan_pesan_ulang']);
        });
    }
};
