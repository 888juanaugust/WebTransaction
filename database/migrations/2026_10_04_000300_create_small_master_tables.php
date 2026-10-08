<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer / vendor / item categories: a tree (parent_id), one default.
        foreach (['customer_categories', 'vendor_categories'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('name', 100);
                $table->foreignId('parent_id')->nullable()->constrained($name)->restrictOnDelete();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->unique(['name', 'parent_id']);
            });
        }

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('parent_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            // The accounts an item inherits unless it sets its own.
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('sales_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('sales_return_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('purchase_return_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['name', 'parent_id']);
        });

        Schema::create('item_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        // Price categories: a customer's price level; an item carries one price per level.
        Schema::create('price_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('discount_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            // The e-tax unit code (UM.0018 and the like) the unit reports as.
            $table->string('unit_tax_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->string('pic', 100)->nullable();
            $table->boolean('scrap_warehouse')->default(false);
            $table->boolean('is_default')->default(false);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('used_all_user')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouse_users', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['warehouse_id', 'user_id']);
        });

        // General contacts, outside customers, vendors and employees.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_type', 20)->default('other');
            $table->string('company', 150)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('mobile_phone', 30)->nullable();
            $table->string('work_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['contacts', 'warehouse_users', 'warehouses', 'units', 'vendor_types', 'discount_categories', 'price_categories', 'item_brands', 'item_categories', 'vendor_categories', 'customer_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
