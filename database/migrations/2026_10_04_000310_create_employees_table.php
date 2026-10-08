<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('salutation', 10)->nullable();
            $table->string('name', 150);
            $table->string('nik_no', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('mobile_phone', 30)->nullable();
            $table->string('work_phone', 30)->nullable();
            $table->string('home_phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('website', 150)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('position', 100)->nullable();
            $table->date('join_date')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('is_salesman')->default(false);
            $table->text('notes')->nullable();
            // Address
            $table->text('street')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('zip_code', 10)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('country', 80)->nullable();
            // Income tax
            $table->boolean('withhold_income_tax')->default(false);
            $table->string('npwp_no', 30)->nullable();
            $table->string('work_status', 40)->nullable();
            $table->string('tax_status', 10)->nullable();
            $table->unsignedTinyInteger('start_month_payment')->nullable();
            $table->unsignedSmallInteger('start_year_payment')->nullable();
            $table->bigInteger('previous_income')->default(0);
            $table->bigInteger('previous_tax')->default(0);
            // Salary account
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('bank_account', 50)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('is_salesman');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
