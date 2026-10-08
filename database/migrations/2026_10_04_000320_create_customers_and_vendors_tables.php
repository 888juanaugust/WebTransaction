<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('name', 150);
            $table->foreignId('category_id')->nullable()->constrained('customer_categories')->restrictOnDelete();
            $table->string('work_phone', 30)->nullable();
            $table->string('mobile_phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('fax', 30)->nullable();
            $table->string('website', 150)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            self::address($table, 'bill');
            $table->boolean('ship_same_as_bill')->default(true);
            self::address($table, 'ship');
            // Sales
            $table->foreignId('price_category_id')->nullable()->constrained('price_categories')->restrictOnDelete();
            $table->foreignId('discount_category_id')->nullable()->constrained('discount_categories')->restrictOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->decimal('default_sales_disc', 8, 4)->default(0);
            $table->string('default_invoice_desc', 255)->nullable();
            foreach (['receivable', 'down_payment', 'sales', 'item_discount', 'cogs', 'sales_return', 'sales_discount'] as $account) {
                $table->foreignId("{$account}_account_id")->nullable()->constrained('accounts')->restrictOnDelete();
            }
            // Tax
            $table->boolean('default_inc_tax')->default(true);
            $table->string('wp_type', 10)->nullable();
            $table->string('wp_number', 30)->nullable();
            $table->string('wp_name', 150)->nullable();
            $table->string('nitku', 30)->nullable();
            $table->string('country_tax_code', 5)->nullable();
            $table->string('document_code', 20)->nullable();
            $table->boolean('tax_same_as_bill')->default(true);
            self::address($table, 'tax');
            // Credit limit
            $table->string('credit_limit_mode', 20)->default('per_customer');
            $table->foreignId('parent_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->boolean('credit_limit_age_enabled')->default(false);
            $table->unsignedSmallInteger('credit_limit_age_days')->default(0);
            $table->boolean('credit_limit_amount_enabled')->default(false);
            $table->bigInteger('credit_limit_amount')->default(0);
            // Other
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('name', 150);
            $table->string('position', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('mobile_phone', 30)->nullable();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('address');
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('name', 150);
            $table->foreignId('category_id')->nullable()->constrained('vendor_categories')->restrictOnDelete();
            $table->foreignId('vendor_type_id')->nullable()->constrained('vendor_types')->restrictOnDelete();
            $table->string('work_phone', 30)->nullable();
            $table->string('mobile_phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('fax', 30)->nullable();
            $table->string('website', 150)->nullable();
            $table->boolean('service_seller')->default(false);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            self::address($table, 'bill');
            // Purchasing
            $table->decimal('default_purchase_disc', 8, 4)->default(0);
            $table->string('default_invoice_desc', 255)->nullable();
            $table->foreignId('payable_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('down_payment_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            // Tax
            $table->boolean('default_inc_tax')->default(true);
            $table->string('wp_type', 10)->nullable();
            $table->string('wp_number', 30)->nullable();
            $table->string('wp_name', 150)->nullable();
            $table->string('nitku', 30)->nullable();
            $table->string('document_code', 20)->nullable();
            $table->boolean('tax_same_as_bill')->default(true);
            self::address($table, 'tax');
            // Other
            $table->boolean('use_bill_number')->default(false);
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('name', 150);
            $table->string('position', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('mobile_phone', 30)->nullable();
        });

        Schema::create('vendor_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('bank_account', 50);
            $table->string('bank_account_name', 150)->nullable();
        });

        // The "Saldo Piutang / Saldo Utang" tab: balances carried in from before
        // the data start date. The ledger module turns them into open invoices.
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('party_type', 20);
            $table->unsignedBigInteger('party_id');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->date('trans_date');
            $table->bigInteger('amount');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->string('number', 60)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->index(['party_type', 'party_id']);
        });
    }

    private static function address(Blueprint $table, string $prefix): void
    {
        $table->text("{$prefix}_street")->nullable();
        $table->string("{$prefix}_city", 80)->nullable();
        $table->string("{$prefix}_zip_code", 10)->nullable();
        $table->string("{$prefix}_province", 80)->nullable();
        $table->string("{$prefix}_country", 80)->nullable();
    }

    public function down(): void
    {
        foreach (['opening_balances', 'vendor_bank_accounts', 'vendor_contacts', 'vendors', 'customer_addresses', 'customer_contacts', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
