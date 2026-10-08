<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('phone_number', 30)->nullable();
            $table->string('nitku', 30)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('used_all_user')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_users', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('symbol', 10);
            $table->string('name', 80);
            $table->string('country', 80)->nullable();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('swift_code', 20)->nullable();
            $table->timestamps();
        });

        // The chart of accounts. Its screen is a General Ledger phase; the table
        // exists now because tax codes and the default-account preferences point at it.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('no', 30)->unique();
            $table->string('name', 150);
            $table->string('account_type', 40);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_sub')->default(false);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->text('memo')->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('bank_account', 50)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('used_all_user')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('account_type');
        });

        Schema::create('account_users', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['account_id', 'user_id']);
        });

        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('tax_type', 30);
            $table->string('description', 120);
            $table->decimal('rate_percent', 8, 4)->default(0);
            // The tax base as a fraction of the price: 11/12 for the 12 % VAT whose burden stays 11 %.
            $table->unsignedSmallInteger('dpp_numerator')->default(1);
            $table->unsignedSmallInteger('dpp_denominator')->default(1);
            $table->foreignId('sales_tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('purchase_tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->unsignedSmallInteger('discount_days')->default(0);
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->text('memo')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('pic_name', 100)->nullable();
            $table->string('pic_phone_number', 30)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['fobs', 'shipments', 'payment_terms', 'tax_codes', 'account_users', 'accounts', 'banks', 'currencies', 'branch_users', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
