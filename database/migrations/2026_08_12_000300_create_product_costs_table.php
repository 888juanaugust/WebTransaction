<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving-average cost, held as a (quantity, value) pair.
 *
 * WHY A PAIR AND NOT A UNIT COST
 * ------------------------------
 * The obvious design stores `unit_cost_rupiah` and recomputes it on each
 * receipt. It drifts. Money here is integer rupiah, so every recomputation
 * rounds, and the rounding compounds: after a few hundred receipts the stated
 * unit cost multiplied by the quantity on hand no longer equals what was
 * actually paid, and inventory value stops tying to the ledger.
 *
 * Holding quantity and total value instead makes both exact. Value is only ever
 * added to or subtracted from — never recomputed — and unit cost is derived
 * when somebody asks for it. Rounding then happens once, at the point of
 * display or at the point a shipment freezes its COGS, and never accumulates.
 *
 * WHY GLOBAL PER SKU AND NOT PER WAREHOUSE
 * ----------------------------------------
 * Inventory is valued for the company, not for a building. Keeping one average
 * per SKU also makes a warehouse transfer value-neutral by construction — goods
 * leave one shelf and arrive on another at the same cost, so no transfer can
 * invent or destroy value. Per-warehouse averages would need cost to travel
 * with the transfer document, which is a second thing to get wrong for no gain
 * on any report anybody has asked for.
 *
 * Like stock_levels, this is a cache. InventoryValuation::reconcile() rebuilds
 * it by replaying stock_movements, and the invariant is that doing so changes
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();

            // Base units on hand across every warehouse, and what they cost.
            $table->bigInteger('qty_base')->default(0);
            $table->bigInteger('value_rupiah')->default(0);

            // The last cost actually paid, kept for reference — buyers ask
            // "what did we pay last time", which the average cannot answer.
            $table->bigInteger('last_cost_rupiah')->nullable();
            $table->timestamp('last_received_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costs');
    }
};
