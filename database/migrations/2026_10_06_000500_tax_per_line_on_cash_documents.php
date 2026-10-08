<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, receipts and expense accruals carry tax per line, like priced
 * documents: a tax code, its tax base and tax, and the supplier's tax
 * invoice number; the header says whether the amounts include tax.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['cash_payments' => 'cash_payment_lines', 'cash_receipts' => 'cash_receipt_lines', 'expense_accruals' => 'expense_accrual_lines'];

    public function up(): void
    {
        foreach (self::DOCUMENTS as $header => $lines) {
            Schema::table($header, fn (Blueprint $table) => $table->boolean('inclusive_tax')->default(false));
            Schema::table($lines, function (Blueprint $table) {
                $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
                $table->bigInteger('dpp_amount')->default(0);
                $table->bigInteger('tax_amount')->default(0);
                $table->string('tax_invoice_number', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::DOCUMENTS as $header => $lines) {
            Schema::table($lines, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tax_code_id');
                $table->dropColumn(['dpp_amount', 'tax_amount', 'tax_invoice_number']);
            });
            Schema::table($header, fn (Blueprint $table) => $table->dropColumn('inclusive_tax'));
        }
    }
};
