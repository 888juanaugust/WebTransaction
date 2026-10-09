<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stock reservations ledger: what approved orders hold in each warehouse.
 * Append-only, signed: a hold is positive; a consume (the delivery took the
 * goods), a release (rejection, an edit that reopens the approval, goods
 * delivered from elsewhere) is negative; a reopen (the delivery was unposted)
 * is positive again and names the row it reverses. What an order line holds
 * is the sum of its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('kind', 12); // held | consumed | released | reopened
            $table->decimal('quantity', 18, 4); // signed in base units
            $table->string('reason', 40)->nullable();
            $table->foreignId('posting_id')->nullable()->constrained('postings')->nullOnDelete();
            $table->foreignId('reverses_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['item_id', 'warehouse_id']);
            $table->index(['sales_order_id', 'sales_order_line_id']);
            $table->index('posting_id');
        });

        // The ledger is never edited or trimmed: a wrong row is reversed by another row.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_reservations_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_reservations is append-only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_reservations_no_update BEFORE UPDATE OR DELETE ON stock_reservations
                FOR EACH ROW EXECUTE FUNCTION stock_reservations_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_reservations_no_update ON stock_reservations; DROP FUNCTION IF EXISTS stock_reservations_append_only();');
        Schema::dropIfExists('stock_reservations');
    }
};
