<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A settlement claim: a seat says the customer paid an invoice; Finance verifies it into a receipt. One filed claim per invoice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->text('account'); // where, when and how the money was handed over
            $table->string('status', 16)->default('filed'); // filed | verified | rejected
            $table->foreignId('filed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('sales_receipt_id')->nullable()->constrained('sales_receipts')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index('status');
        });
        DB::statement("CREATE UNIQUE INDEX settlement_claims_one_filed_per_invoice ON settlement_claims (sales_invoice_id) WHERE status = 'filed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_claims');
    }
};
