<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The collection desk's record: every contact about an unpaid invoice, and the promise it brought, if any. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('method', 16); // phone | whatsapp | visit | email
            $table->string('outcome', 16); // promise | asks_time | unreachable | dispute | paid
            $table->date('promise_date')->nullable();
            $table->bigInteger('promise_amount')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('contacted_at');
            $table->timestamps();
            $table->index(['sales_invoice_id', 'contacted_at']);
            $table->index('promise_date');
            $table->index(['customer_id', 'contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_contacts');
    }
};
