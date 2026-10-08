<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each priced line keeps its share of the "Discount on the total", so what
 * the line is worth to the books (revenue, cost, commissions) is net of it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const LINES = [
        'sales_quotation_lines', 'sales_order_lines', 'delivery_lines', 'sales_invoice_lines', 'sales_return_lines',
        'purchase_order_lines', 'goods_receipt_lines', 'purchase_invoice_lines', 'purchase_return_lines',
    ];

    public function up(): void
    {
        foreach (self::LINES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->bigInteger('header_discount')->default(0)->after('discount_amount'));
        }
    }

    public function down(): void
    {
        foreach (self::LINES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('header_discount'));
        }
    }
};
