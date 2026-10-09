<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The team in charge of a customer: one sales and one marketing seat, assigned by the owner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('sales_user_id')->nullable()->after('salesman_id')->constrained('users')->nullOnDelete();
            $table->foreignId('marketing_user_id')->nullable()->after('sales_user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_user_id');
            $table->dropConstrainedForeignId('marketing_user_id');
        });
    }
};
