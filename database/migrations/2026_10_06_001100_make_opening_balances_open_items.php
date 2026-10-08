<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's or vendor's opening balance is an open invoice brought in at
 * the data start: it posts to the receivable or payable account, is settled
 * by receipts and payments, and ages from its own invoice date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_balances', function (Blueprint $table) {
            $table->date('document_date')->nullable()->after('trans_date'); // the original invoice's date, for aging
            $table->date('due_date')->nullable()->after('document_date');
            $table->foreignId('branch_id')->nullable()->after('party_id')->constrained('branches')->restrictOnDelete();
            $table->bigInteger('paid_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('opening_balances')->update(['document_date' => DB::raw('trans_date')]);
    }

    public function down(): void
    {
        Schema::table('opening_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['document_date', 'due_date', 'paid_amount', 'payment_status']);
        });
    }
};
