<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');

            $table->string('bank_code', 20);
            $table->string('account_number', 40);

            // Xendit's id for this fixed VA.
            $table->string('external_id', 100)->nullable();
            $table->string('gateway_id', 100)->nullable();

            // active | inactive
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // A company keeps one fixed VA per bank, forever.
            $table->unique(['company_id', 'bank_code']);
            $table->unique('account_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_accounts');
    }
};
