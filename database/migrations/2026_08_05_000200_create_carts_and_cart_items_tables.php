<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A buyer's basket, before it becomes an order.
 *
 * ## No prices here, on purpose
 *
 * There is not a single money column in either table, and there must never be
 * one. A price is decided by resolvePrice() and becomes binding when the line
 * snapshots it at `confirmed`. A rupiah figure stored in a cart would be a
 * second source of truth for the same number — stale the moment a price list is
 * published, and exactly the figure a customer would quote back at us. The cart
 * holds what was asked for; what it costs is answered live and marked
 * indicative until staff confirm.
 *
 * ## One cart per login, not per company
 *
 * A bengkel may have several people with logins. Sharing one basket between
 * them means two people editing the same quantities with no way to tell whose
 * change won. Company scoping still exists for isolation — `company_id` is
 * denormalised here so the portal's scoping rule applies unchanged — but the
 * basket belongs to the person filling it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            // Denormalised from the owner so the portal's company scoping and
            // its "one query, one where" rule work here like everywhere else.
            $table->foreignId('company_id')->constrained('companies');

            $table->foreignId('customer_user_id')
                ->unique()
                ->constrained('customer_users')
                ->cascadeOnDelete();

            // Which gudang this basket is destined for. Kept on the cart rather
            // than asked at checkout so the stock indication next to each line
            // is about a real warehouse.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');

            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();

            $table->string('sku', 60);
            $table->string('ordered_unit', 10);
            $table->integer('ordered_qty');

            $table->timestamps();

            /*
             * Same SKU twice in the same unit is one line with a bigger number,
             * not two lines — otherwise "add to cart" twice looks like it did
             * nothing the second time.
             *
             * The unit is part of the key because the same SKU in PCS and in
             * DUS are genuinely different lines, which is also how order_lines
             * models it.
             */
            $table->unique(['cart_id', 'sku', 'ordered_unit']);

            $table->foreign('sku')->references('kode')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
