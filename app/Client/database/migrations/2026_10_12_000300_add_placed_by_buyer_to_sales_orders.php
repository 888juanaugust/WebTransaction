<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The buyer login that placed an order from the portal; the order itself is written in the Portal user's name. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('placed_by_customer_user_id')->nullable()->after('split_parent_id')->constrained('customer_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('placed_by_customer_user_id');
        });
    }
};
