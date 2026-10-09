<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The list prices as versions: a version is published once and never
 * changed; the next price list is the next version. Every item of a
 * version carries its price per base unit and its carton size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_versions', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->string('status', 20)->default('draft'); // draft | published | superseded
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_file_path', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'effective_from']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('price_list_versions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->bigInteger('price');
            $table->unsignedInteger('qty_per_ctn')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['version_id', 'item_id']);
        });

        Schema::create('customer_price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->cascadeOnDelete(); // null: every item
            $table->decimal('min_base_quantity', 18, 4)->default(1);
            $table->bigInteger('price')->nullable();
            $table->decimal('discount_percent', 8, 4)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('reason', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'item_id']);
        });

        Schema::table('price_categories', function (Blueprint $table) {
            $table->decimal('blanket_discount_percent', 8, 4)->default(0)->after('notes');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('part_number', 100)->nullable()->after('upc_no');
            $table->string('vehicle', 150)->nullable()->after('part_number');
            $table->string('product_type', 100)->nullable()->after('vehicle');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('price_reason', 32)->nullable()->after('discount_percent');
            $table->foreignId('price_list_version_id')->nullable()->after('price_reason')->constrained('price_list_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_version_id');
            $table->dropColumn('price_reason');
        });
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn(['part_number', 'vehicle', 'product_type']));
        Schema::table('price_categories', fn (Blueprint $table) => $table->dropColumn('blanket_discount_percent'));
        Schema::dropIfExists('customer_price_rules');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_list_versions');
    }
};
