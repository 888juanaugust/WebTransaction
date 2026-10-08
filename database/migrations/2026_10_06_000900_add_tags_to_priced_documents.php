<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The priced documents carry a department and a project too: on the header
 * (the default), on every line and on every charge, so revenue, cost of
 * sales and purchases read per department or project. A pulled line keeps
 * its source's.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TAGGED = [
        'sales_quotations', 'sales_quotation_lines', 'sales_quotation_charges',
        'sales_orders', 'sales_order_lines', 'sales_order_charges',
        'deliveries', 'delivery_lines',
        'sales_invoices', 'sales_invoice_lines', 'sales_invoice_charges',
        'sales_returns', 'sales_return_lines', 'sales_return_charges',
        'sales_down_payments', 'sales_receipts',
        'purchase_orders', 'purchase_order_lines', 'purchase_order_charges',
        'goods_receipts', 'goods_receipt_lines',
        'purchase_invoices', 'purchase_invoice_lines', 'purchase_invoice_charges',
        'purchase_returns', 'purchase_return_lines', 'purchase_return_charges',
        'purchase_down_payments', 'purchase_payments',
        'vendor_claims', 'vendor_claim_lines',
        'inventory_adjustments', 'inventory_adjustment_lines',
    ];

    public function up(): void
    {
        foreach (self::TAGGED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->index()->constrained('departments')->restrictOnDelete();
                $table->foreignId('project_id')->nullable()->index()->constrained('projects')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TAGGED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('department_id');
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
