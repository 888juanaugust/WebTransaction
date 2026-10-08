<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A VAT return saved for a period: numbered, with the totals it reported. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('from_date');
            $table->date('until_date');
            $table->bigInteger('vat_out_base')->default(0);
            $table->bigInteger('vat_out')->default(0);
            $table->bigInteger('vat_in_base')->default(0);
            $table->bigInteger('vat_in')->default(0);
            $table->bigInteger('payable')->default(0); // out − in; negative is a surplus to carry
            $table->unsignedInteger('document_count')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['from_date', 'until_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_returns');
    }
};
