<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A buyer's cart: one per login, lines in the item's units, no prices stored (they are priced when shown and when ordered). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_user_id')->unique()->constrained('customer_users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('po_number', 60)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('updated_at');
        });

        Schema::create('portal_cart_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('portal_carts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
            $table->unique(['cart_id', 'item_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_cart_lines');
        Schema::dropIfExists('portal_carts');
    }
};
