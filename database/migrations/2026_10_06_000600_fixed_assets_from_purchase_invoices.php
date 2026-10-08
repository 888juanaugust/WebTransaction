<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A fixed asset may be recorded from a purchase invoice line; one asset per line. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('purchase_invoice_line_id')->nullable()->unique()->constrained('purchase_invoice_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_invoice_line_id'));
    }
};
