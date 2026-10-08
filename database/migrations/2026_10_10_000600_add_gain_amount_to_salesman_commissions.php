<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fixed commission is money: whole rupiah in a BIGINT, beside the percentage (which stays a decimal). Rules
 * already saved with a fixed amount in the decimal column are carried over, rounded half up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesman_commissions', function (Blueprint $table) {
            $table->bigInteger('gain_amount')->default(0)->after('gain_value');
        });
        DB::statement("update salesman_commissions set gain_amount = round(gain_value), gain_value = 0 where gain_type = 'fixed'");
    }

    public function down(): void
    {
        DB::statement("update salesman_commissions set gain_value = gain_amount where gain_type = 'fixed'");
        Schema::table('salesman_commissions', function (Blueprint $table) {
            $table->dropColumn('gain_amount');
        });
    }
};
