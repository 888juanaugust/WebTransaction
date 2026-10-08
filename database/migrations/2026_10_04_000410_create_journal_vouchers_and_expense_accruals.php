<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->bigInteger('total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        Schema::create('journal_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_voucher_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('memo', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        });

        // Expense accrual: expenses booked against a payable, settled later by a payment.
        Schema::create('expense_accruals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->date('due_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('payable_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->bigInteger('total')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->boolean('is_printed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
            $table->index('status');
        });

        Schema::create('expense_accrual_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_accrual_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->string('memo', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['expense_accrual_lines', 'expense_accruals', 'journal_voucher_lines', 'journal_vouchers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
