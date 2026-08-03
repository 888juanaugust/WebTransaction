<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('warehouse_id')->constrained('warehouses');

            // draft | submitted | confirmed | awaiting_payment | paid
            // | shipped | completed | rejected | expired
            // Never flipped in place: every change goes through the state
            // machine and writes an order_events row.
            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('sales_user_id')->nullable()->constrained('users');

            // All BIGINT rupiah, summed from the line snapshots at confirmed.
            $table->bigInteger('subtotal_rupiah')->default(0);
            $table->bigInteger('discount_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->bigInteger('total_rupiah')->default(0);

            // Which price list the lines were priced against.
            $table->foreignId('price_list_version_id')->nullable()->constrained('price_list_versions');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // A scheduled job releases reservations on stale unpaid orders.
            $table->timestamp('reservation_expires_at')->nullable();

            $table->string('po_pelanggan', 60)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index('reservation_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
