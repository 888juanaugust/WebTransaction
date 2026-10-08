<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's discount category is a price category whose discount
 * adjustments apply to them; a price adjustment line may start from a
 * quantity (a wholesale break).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('discount_price_category_id')->nullable()->after('price_category_id')->constrained('price_categories')->nullOnDelete();
        });
        Schema::table('selling_price_adjustment_lines', function (Blueprint $table) {
            $table->decimal('min_quantity', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('selling_price_adjustment_lines', fn (Blueprint $table) => $table->dropColumn('min_quantity'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropConstrainedForeignId('discount_price_category_id'));
    }
};
