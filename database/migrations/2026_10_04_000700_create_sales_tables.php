<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private static function pricedHeader(Blueprint $table): void
    {
        $table->id();
        $table->string('number', 40)->unique();
        $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
        $table->date('trans_date');
        $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
        $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        $table->string('description', 255)->nullable();
        $table->string('status', 20)->default('pending');
        $table->boolean('is_printed')->default(false);
        $table->boolean('taxable')->default(true);
        $table->boolean('inclusive_tax')->default(false);
        $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
        $table->string('po_number', 60)->nullable();
        $table->text('to_address')->nullable();
        $table->date('ship_date')->nullable();
        $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
        $table->foreignId('fob_id')->nullable()->constrained('fobs')->restrictOnDelete();
        $table->bigInteger('subtotal')->default(0);
        $table->decimal('discount_percent', 8, 4)->default(0);
        $table->bigInteger('discount_amount')->default(0);
        $table->bigInteger('charges_total')->default(0);
        $table->bigInteger('dpp_total')->default(0);
        $table->bigInteger('tax_total')->default(0);
        $table->bigInteger('total')->default(0);
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->index('trans_date');
        $table->index('status');
    }

    private static function pricedLine(Blueprint $table, string $doc): void
    {
        $table->id();
        $table->foreignId("{$doc}_id")->constrained()->cascadeOnDelete();
        $table->unsignedSmallInteger('sort')->default(0);
        $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
        $table->decimal('quantity', 18, 4);
        $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
        $table->decimal('base_quantity', 18, 4);
        $table->decimal('unit_price', 18, 4)->default(0);
        $table->decimal('discount_percent', 8, 4)->default(0);
        $table->bigInteger('discount_amount')->default(0);
        $table->bigInteger('amount')->default(0);
        $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
        $table->bigInteger('dpp_amount')->default(0);
        $table->bigInteger('tax_amount')->default(0);
        $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
        $table->foreignId('salesman_id')->nullable()->constrained('employees')->restrictOnDelete();
        $table->string('memo', 255)->nullable();
        $table->decimal('processed_quantity', 18, 4)->default(0);
        $table->string('source_line_type', 60)->nullable();
        $table->unsignedBigInteger('source_line_id')->nullable();
        $table->index(['source_line_type', 'source_line_id']);
    }

    private static function charges(Blueprint $table, string $doc): void
    {
        $table->id();
        $table->foreignId("{$doc}_id")->constrained()->cascadeOnDelete();
        $table->unsignedSmallInteger('sort')->default(0);
        $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
        $table->bigInteger('amount')->default(0);
        $table->string('description', 255)->nullable();
        $table->boolean('allocate_to_cost')->default(false);
    }

    public function up(): void
    {
        Schema::create('sales_quotations', fn (Blueprint $table) => self::pricedHeader($table));
        Schema::create('sales_quotation_lines', fn (Blueprint $table) => self::pricedLine($table, 'sales_quotation'));
        Schema::create('sales_quotation_charges', fn (Blueprint $table) => self::charges($table, 'sales_quotation'));

        Schema::create('sales_orders', function (Blueprint $table) {
            self::pricedHeader($table);
            // The approval every order waits for when the marketing-approval rule is on.
            $table->string('approval_status', 20)->default('approved');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
        });
        Schema::create('sales_order_lines', fn (Blueprint $table) => self::pricedLine($table, 'sales_order'));
        Schema::create('sales_order_charges', fn (Blueprint $table) => self::charges($table, 'sales_order'));

        Schema::create('deliveries', fn (Blueprint $table) => self::pricedHeader($table));
        Schema::create('delivery_lines', fn (Blueprint $table) => self::pricedLine($table, 'delivery'));

        Schema::create('sales_down_payments', function (Blueprint $table) {
            self::pricedHeader($table);
            $table->bigInteger('amount')->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('used_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('nsfp', 40)->nullable();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            self::pricedHeader($table);
            $table->date('due_date')->nullable();
            $table->bigInteger('down_payment_total')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('nsfp', 40)->nullable();
            $table->timestamp('nsfp_filed_at')->nullable();
        });
        Schema::create('sales_invoice_lines', fn (Blueprint $table) => self::pricedLine($table, 'sales_invoice'));
        Schema::create('sales_invoice_charges', fn (Blueprint $table) => self::charges($table, 'sales_invoice'));
        Schema::create('sales_invoice_down_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_down_payment_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
        });

        Schema::create('sales_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->string('cheque_no', 40)->nullable();
            $table->date('cheque_date')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->boolean('use_credit')->default(false);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });
        Schema::create('sales_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('receivable_type', 60);
            $table->unsignedBigInteger('receivable_id');
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->foreignId('discount_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->index(['receivable_type', 'receivable_id']);
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            self::pricedHeader($table);
            $table->string('return_type', 20)->default('invoice');
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->bigInteger('paid_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            // Posted by the inventory side once the goods are back (segregation of duties).
            $table->string('posting_status', 20)->default('posted');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('sales_return_lines', fn (Blueprint $table) => self::pricedLine($table, 'sales_return'));
        Schema::create('sales_return_charges', fn (Blueprint $table) => self::charges($table, 'sales_return'));

        Schema::create('invoice_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('collect_date');
            $table->date('due_date');
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->bigInteger('total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('invoice_exchange_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_exchange_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
        });

        Schema::create('selling_price_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->foreignId('price_category_id')->constrained('price_categories')->restrictOnDelete();
            $table->string('sales_adjustment_type', 10)->default('price');
            $table->date('trans_date');
            $table->date('end_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['price_category_id', 'trans_date']);
        });
        Schema::create('selling_price_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selling_price_adjustment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('value', 18, 4)->default(0);
        });

        Schema::create('salesman_commissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('active_period', 10)->default('forever');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('salesman_scope', 10)->default('all');
            $table->json('levels')->nullable();
            $table->string('requirement', 20)->default('none');
            $table->bigInteger('requirement_from')->default(0);
            $table->bigInteger('requirement_to')->default(0);
            $table->decimal('requirement_qty', 18, 4)->default(0);
            $table->string('gain_type', 10)->default('percent');
            $table->decimal('gain_value', 18, 4)->default(0);
            $table->string('gain_basis', 20)->default('sales_value');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('salesman_commission_employees', function (Blueprint $table) {
            $table->foreignId('salesman_commission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->primary(['salesman_commission_id', 'employee_id']);
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('target_type', 20)->default('per_salesman');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date');
            $table->text('notes')->nullable();
            $table->string('analyst_name', 100)->nullable();
            $table->timestamps();
        });
        Schema::create('sales_target_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->unsignedTinyInteger('month')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->bigInteger('value')->default(0);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->date('trans_date');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('customer_name', 150);
            $table->foreignId('salesman_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });
    }

    public function down(): void
    {
        foreach (['check_ins', 'sales_target_lines', 'sales_targets', 'salesman_commission_employees', 'salesman_commissions', 'selling_price_adjustment_lines', 'selling_price_adjustments', 'invoice_exchange_lines', 'invoice_exchanges', 'sales_return_charges', 'sales_return_lines', 'sales_returns', 'sales_receipt_lines', 'sales_receipts', 'sales_invoice_down_payments', 'sales_invoice_charges', 'sales_invoice_lines', 'sales_invoices', 'sales_down_payments', 'delivery_lines', 'deliveries', 'sales_order_charges', 'sales_order_lines', 'sales_orders', 'sales_quotation_charges', 'sales_quotation_lines', 'sales_quotations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
