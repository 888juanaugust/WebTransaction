<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A sales expense claim: what a sales user spent on the road or for one of their customers; Finance verifies it into a cash payment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('trans_date');
            $table->bigInteger('amount');
            $table->text('description');
            $table->string('status', 16)->default('filed'); // filed | verified | rejected
            $table->foreignId('filed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('cash_payment_id')->nullable()->constrained('cash_payments')->nullOnDelete();
            $table->timestamps();
            $table->index(['sales_user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};
