<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A customer type carries the terms its customers trade on: a price tier (its promo), a payment term and a credit age limit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->foreignId('price_category_id')->nullable()->constrained('price_categories')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->unsignedSmallInteger('credit_limit_age_days')->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('customer_type_id')->nullable()->after('category_id')->constrained('customer_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_type_id');
        });
        Schema::dropIfExists('customer_types');
    }
};
