<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A return claim: the sales seat says goods of an invoice come back to a warehouse; Inventory verifies it into a sales return. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 16)->default('filed'); // filed | verified | rejected
            $table->foreignId('filed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index('status');
        });

        Schema::create('return_claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_claim_id')->constrained('return_claims')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('base_quantity', 18, 4);
            $table->index('sales_invoice_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_claim_lines');
        Schema::dropIfExists('return_claims');
    }
};
