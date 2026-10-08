<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment line can settle an expense accrual or a payroll entry, so both
 * get paid through the allocation ledger like invoices. Accruals gain the
 * payment_status column every settled document has; their old status column
 * stays, unwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_payment_lines', function (Blueprint $table) {
            $table->string('payable_type', 40)->nullable()->after('account_id');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payable_type');
            $table->index(['payable_type', 'payable_id']);
        });

        Schema::table('expense_accruals', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('unpaid')->after('paid_amount');
            $table->index('payment_status');
        });
        DB::table('expense_accruals')->update(['payment_status' => DB::raw('status')]);
    }

    public function down(): void
    {
        Schema::table('expense_accruals', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropColumn('payment_status');
        });
        Schema::table('cash_payment_lines', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
            $table->dropColumn(['payable_type', 'payable_id']);
        });
    }
};
