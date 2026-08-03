<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_price_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // NULL kode = a blanket override for this company.
            $table->string('kode', 60)->nullable();

            $table->bigInteger('min_qty_base')->default(1);

            // Exactly one of these is set.
            $table->bigInteger('harga')->nullable();
            $table->integer('discount_bps')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            // Every price override is logged with who granted it and why.
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->text('alasan')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'kode', 'min_qty_base'], 'company_price_overrides_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_price_overrides');
    }
};
