<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents in a foreign currency. Every existing money column stays in the
 * base currency (ledgers, tax, aging and reports keep reading only those);
 * the amounts in the document's own currency sit beside them as fc_*
 * columns, in that currency's minor units (cents). A null currency is the
 * base currency, and then every fc_* column stays null.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const HEADERS = [
        'sales_quotations', 'sales_orders', 'deliveries', 'sales_invoices', 'sales_returns', 'sales_down_payments',
        'purchase_orders', 'goods_receipts', 'purchase_invoices', 'purchase_returns', 'purchase_down_payments',
    ];

    /** @var list<string> */
    private const LINES = [
        'sales_quotation_lines', 'sales_order_lines', 'delivery_lines', 'sales_invoice_lines', 'sales_return_lines',
        'purchase_order_lines', 'goods_receipt_lines', 'purchase_invoice_lines', 'purchase_return_lines',
    ];

    /** @var list<string> */
    private const CHARGES = [
        'sales_quotation_charges', 'sales_order_charges', 'sales_invoice_charges', 'sales_return_charges',
        'purchase_order_charges', 'purchase_invoice_charges', 'purchase_return_charges',
        'sales_invoice_down_payments', 'purchase_invoice_down_payments',
    ];

    public function up(): void
    {
        Schema::table('currencies', fn (Blueprint $t) => $t->unsignedTinyInteger('decimals')->default(2));
        DB::table('currencies')->whereIn('code', ['IDR', 'JPY', 'KRW', 'VND'])->update(['decimals' => 0]);
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->date('valid_from');
            $table->decimal('rate', 20, 8);          // base currency per one unit
            $table->decimal('tax_rate', 20, 8)->nullable(); // the Minister of Finance's rate for VAT, when it differs
            $table->timestamps();
            $table->unique(['currency_id', 'valid_from']);
        });
        foreach (['customers', 'vendors'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete());
        }
        foreach (self::HEADERS as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
                $t->decimal('exchange_rate', 20, 8)->default(1);
                $t->decimal('tax_exchange_rate', 20, 8)->nullable();
                foreach (['fc_subtotal', 'fc_discount_amount', 'fc_charges_total', 'fc_tax_total', 'fc_total', 'fc_amount', 'fc_paid_amount', 'fc_used_amount', 'fc_down_payment_total'] as $column) {
                    $t->bigInteger($column)->nullable();
                }
            });
        }
        foreach (self::LINES as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->decimal('fc_unit_price', 18, 4)->nullable();
                foreach (['fc_discount_amount', 'fc_header_discount', 'fc_amount', 'fc_tax_amount'] as $column) {
                    $t->bigInteger($column)->nullable();
                }
            });
        }
        foreach (self::CHARGES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->bigInteger('fc_amount')->nullable());
        }
        foreach (['sales_receipts', 'purchase_payments'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
                $t->decimal('exchange_rate', 20, 8)->default(1);
                $t->bigInteger('fc_amount')->nullable();
            });
        }
        foreach (['sales_receipt_lines', 'purchase_payment_lines'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->bigInteger('fc_amount')->nullable();
                $t->bigInteger('fc_discount')->nullable();
            });
        }
        Schema::table('payment_allocations', function (Blueprint $t) {
            $t->bigInteger('fc_amount')->nullable();
            $t->bigInteger('fc_discount')->nullable();
            $t->bigInteger('fx_difference')->default(0); // realised: what was received or paid, less the carrying value settled
        });
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $t->bigInteger('fc_amount')->nullable(); // signed, debit positive; only on lines of a foreign-currency bank account
        });
        Schema::table('opening_balances', function (Blueprint $t) {
            $t->decimal('exchange_rate', 20, 8)->default(1);
            $t->bigInteger('fc_amount')->nullable();
            $t->bigInteger('fc_paid_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opening_balances', fn (Blueprint $t) => $t->dropColumn(['exchange_rate', 'fc_amount', 'fc_paid_amount']));
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('currency_id');
            $t->dropColumn('fc_amount');
        });
        Schema::table('payment_allocations', fn (Blueprint $t) => $t->dropColumn(['fc_amount', 'fc_discount', 'fx_difference']));
        foreach (['sales_receipt_lines', 'purchase_payment_lines'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn(['fc_amount', 'fc_discount']));
        }
        foreach (['sales_receipts', 'purchase_payments'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->dropConstrainedForeignId('currency_id');
                $t->dropColumn(['exchange_rate', 'fc_amount']);
            });
        }
        foreach (self::CHARGES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('fc_amount'));
        }
        foreach (self::LINES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn(['fc_unit_price', 'fc_discount_amount', 'fc_header_discount', 'fc_amount', 'fc_tax_amount']));
        }
        foreach (self::HEADERS as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->dropConstrainedForeignId('currency_id');
                $t->dropColumn(['exchange_rate', 'tax_exchange_rate', 'fc_subtotal', 'fc_discount_amount', 'fc_charges_total', 'fc_tax_total', 'fc_total', 'fc_amount', 'fc_paid_amount', 'fc_used_amount', 'fc_down_payment_total']);
            });
        }
        foreach (['customers', 'vendors'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('currency_id'));
        }
        Schema::dropIfExists('currency_rates');
        Schema::table('currencies', fn (Blueprint $t) => $t->dropColumn('decimals'));
    }
};
