<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per invoice that got the aging notice: the row is written before the mail, so a second run sends nothing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_invoice_id')->unique()->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedSmallInteger('days'); // the invoice's age when noticed
            $table->jsonb('sent_to')->nullable(); // the addresses the mail went to; empty when nobody had one
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_notices');
    }
};
