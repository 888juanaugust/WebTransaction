<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // The stock ledger: every movement of every item in every warehouse, in
        // base units, with the cost it carried. Written only by postings; append-only.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_id')->constrained('postings')->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('trans_date');
            $table->string('direction', 3);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->bigInteger('total_cost')->default(0);
            $table->string('source_line_type', 60)->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->index(['item_id', 'warehouse_id', 'trans_date']);
            $table->index('posting_id');
        });
        DB::unprepared('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION ledger_append_only();');

        // The cache the ledger is summed into: quantity, moving-average cost and value per item per warehouse.
        Schema::create('item_costs', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->bigInteger('total_value')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['item_id', 'warehouse_id']);
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->boolean('is_opening')->default(false);
            $table->foreignId('opening_item_id')->nullable()->constrained('items')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_adjustment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('adjustment_type', 10)->default('quantity');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->bigInteger('total_cost')->default(0);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('adjustment_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('memo', 255)->nullable();
        });

        Schema::create('item_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->string('item_transfer_type', 10)->default('send');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('reference_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('reference_transfer_id')->nullable()->constrained('item_transfers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        Schema::create('item_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->string('memo', 255)->nullable();
            $table->decimal('processed_quantity', 18, 4)->default(0);
            $table->foreignId('source_line_id')->nullable()->constrained('item_transfer_lines')->restrictOnDelete();
        });

        Schema::create('stock_opname_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->date('start_date');
            $table->string('person_charged', 100);
            $table->text('description')->nullable();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        foreach (['users' => 'users', 'item_categories' => 'item_categories', 'vendors' => 'vendors', 'item_brands' => 'item_brands'] as $name => $ref) {
            Schema::create("stock_opname_order_{$name}", function (Blueprint $table) use ($ref) {
                $table->foreignId('stock_opname_order_id')->constrained()->cascadeOnDelete();
                $column = Str::singular($ref).'_id';
                $table->foreignId($column)->constrained($ref)->cascadeOnDelete();
                $table->primary(['stock_opname_order_id', $column]);
            });
        }

        Schema::create('stock_opname_results', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('stock_opname_order_id')->constrained('stock_opname_orders')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('inventory_adjustment_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_opname_result_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('counted_qty', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('system_qty', 18, 4)->default(0);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $table) => $table->dropColumn('is_system'));
        foreach (['stock_opname_result_lines', 'stock_opname_results', 'stock_opname_order_item_brands', 'stock_opname_order_vendors', 'stock_opname_order_item_categories', 'stock_opname_order_users', 'stock_opname_orders', 'item_transfer_lines', 'item_transfers', 'inventory_adjustment_lines', 'inventory_adjustments', 'item_costs'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements');
        Schema::dropIfExists('stock_movements');
    }
};
