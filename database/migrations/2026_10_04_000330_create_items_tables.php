<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('name', 200);
            $table->string('item_type', 20)->default('inventory');
            $table->string('upc_no', 50)->nullable();
            $table->foreignId('unit1_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('item_brands')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            // Sales / purchasing
            $table->decimal('default_discount', 8, 4)->default(0);
            $table->bigInteger('sell_price')->default(0);
            $table->decimal('min_sell_qty', 18, 4)->default(0);
            $table->boolean('use_wholesale_price')->default(false);
            $table->foreignId('substitute_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('preferred_vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->foreignId('vendor_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->bigInteger('purchase_price')->default(0);
            $table->decimal('min_purchase_qty', 18, 4)->default(0);
            $table->decimal('min_stock', 18, 4)->default(0);
            $table->string('item_tax_code', 20)->nullable();
            $table->foreignId('tax1_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('tax3_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            // Accounts (blank = the category's)
            foreach (['inventory', 'sales', 'cogs', 'sales_return', 'purchase_return'] as $account) {
                $table->foreignId("{$account}_account_id")->nullable()->constrained('accounts')->restrictOnDelete();
            }
            // Other
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->decimal('weight_gr', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
            $table->index('item_type');
        });

        // Every unit an item is sold or bought in, with its ratio to the base unit (unit1 = 1).
        Schema::create('item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('ratio', 18, 6)->default(1);
            $table->bigInteger('sell_price')->default(0);
            $table->unique(['item_id', 'unit_id']);
        });

        // The selling price per price category (and unit).
        Schema::create('item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('price_category_id')->constrained('price_categories')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->bigInteger('price')->default(0);
            $table->unique(['item_id', 'price_category_id', 'unit_id']);
        });

        // A group item's parts.
        Schema::create('item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
        });

        // The "Stok" tab: quantities on hand at the data start date, per warehouse.
        // The inventory module posts them as the opening adjustment.
        Schema::create('item_opening_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->date('trans_date');
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['item_opening_stocks', 'item_components', 'item_prices', 'item_units', 'items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
