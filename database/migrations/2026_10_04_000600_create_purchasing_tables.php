<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The header every priced document carries (the standard's common fields and the cached totals). */
    private static function pricedHeader(Blueprint $table, string $party, string $partyTable): void
    {
        $table->id();
        $table->string('number', 40)->unique();
        $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
        $table->date('trans_date');
        $table->foreignId("{$party}_id")->constrained($partyTable)->restrictOnDelete();
        $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        $table->string('description', 255)->nullable();
        $table->string('status', 20)->default('pending');
        $table->boolean('is_printed')->default(false);
        $table->boolean('taxable')->default(true);
        $table->boolean('inclusive_tax')->default(false);
        $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
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

    /** The line every priced document carries: ordered and base quantity, price, discount, tax per line, fulfilment. */
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
        // The settlement ledger: what each payment applied to which invoice. Append-only; counts while its posting is active.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_id')->constrained('postings')->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('payment_type', 60);
            $table->unsignedBigInteger('payment_id');
            $table->string('receivable_type', 60);
            $table->unsignedBigInteger('receivable_id');
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->foreignId('discount_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->date('trans_date');
            $table->index(['receivable_type', 'receivable_id']);
            $table->index(['payment_type', 'payment_id']);
        });
        DB::unprepared('CREATE TRIGGER payment_allocations_append_only BEFORE UPDATE OR DELETE ON payment_allocations FOR EACH ROW EXECUTE FUNCTION ledger_append_only();');

        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->string('requisition_type', 10)->default('buy');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('is_printed')->default(false);
            $table->bigInteger('estimated_total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('purchase_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->date('requested_date')->nullable();
            $table->decimal('estimated_price', 18, 4)->default(0);
            $table->string('memo', 255)->nullable();
            $table->decimal('processed_quantity', 18, 4)->default(0);
        });

        Schema::create('vendor_prices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->date('trans_date');
            $table->date('end_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vendor_id', 'trans_date']);
        });
        Schema::create('vendor_price_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_price_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('price', 18, 4)->default(0);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            self::pricedHeader($table, 'vendor', 'vendors');
            $table->foreignId('vendor_bank_account_id')->nullable()->constrained('vendor_bank_accounts')->nullOnDelete();
        });
        Schema::create('purchase_order_lines', fn (Blueprint $table) => self::pricedLine($table, 'purchase_order'));
        Schema::create('purchase_order_charges', fn (Blueprint $table) => self::charges($table, 'purchase_order'));

        Schema::create('goods_receipts', function (Blueprint $table) {
            self::pricedHeader($table, 'vendor', 'vendors');
            $table->string('receive_number', 60)->nullable();
        });
        Schema::create('goods_receipt_lines', fn (Blueprint $table) => self::pricedLine($table, 'goods_receipt'));

        Schema::create('purchase_down_payments', function (Blueprint $table) {
            self::pricedHeader($table, 'vendor', 'vendors');
            $table->bigInteger('amount')->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('vendor_bank_account_id')->nullable()->constrained('vendor_bank_accounts')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('used_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            self::pricedHeader($table, 'vendor', 'vendors');
            $table->string('bill_number', 60)->nullable();
            $table->foreignId('vendor_bank_account_id')->nullable()->constrained('vendor_bank_accounts')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->bigInteger('down_payment_total')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('tax_invoice_number', 40)->nullable();
        });
        Schema::create('purchase_invoice_lines', fn (Blueprint $table) => self::pricedLine($table, 'purchase_invoice'));
        Schema::create('purchase_invoice_charges', fn (Blueprint $table) => self::charges($table, 'purchase_invoice'));
        Schema::create('purchase_invoice_down_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_down_payment_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->string('cheque_no', 40)->nullable();
            $table->date('cheque_date')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });
        Schema::create('purchase_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('payable_type', 60);
            $table->unsignedBigInteger('payable_id');
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->foreignId('discount_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->index(['payable_type', 'payable_id']);
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            self::pricedHeader($table, 'vendor', 'vendors');
            $table->string('return_type', 20)->default('invoice');
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->bigInteger('paid_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid');
        });
        Schema::create('purchase_return_lines', fn (Blueprint $table) => self::pricedLine($table, 'purchase_return'));
        Schema::create('purchase_return_charges', fn (Blueprint $table) => self::charges($table, 'purchase_return'));

        Schema::create('vendor_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->string('claim_type', 10)->default('send');
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('to_address')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('vendor_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_claim_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->string('memo', 255)->nullable();
        });

        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->bigInteger('total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('payment_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('payable_type', 60);
            $table->unsignedBigInteger('payable_id');
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->foreignId('purchase_payment_id')->nullable()->constrained('purchase_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['payment_order_lines', 'payment_orders', 'vendor_claim_lines', 'vendor_claims', 'purchase_return_charges', 'purchase_return_lines', 'purchase_returns', 'purchase_payment_lines', 'purchase_payments', 'purchase_invoice_down_payments', 'purchase_invoice_charges', 'purchase_invoice_lines', 'purchase_invoices', 'purchase_down_payments', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_charges', 'purchase_order_lines', 'purchase_orders', 'vendor_price_lines', 'vendor_prices', 'purchase_requisition_lines', 'purchase_requisitions'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP TRIGGER IF EXISTS payment_allocations_append_only ON payment_allocations');
        Schema::dropIfExists('payment_allocations');
    }
};
