<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A monthly or yearly schedule keeps the day it was set for (the 31st stays month-end), instead of drifting to the 28th. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->unsignedTinyInteger('run_day')->nullable()->after('next_run_on');
        });
        DB::statement('update recurring_transactions set run_day = extract(day from next_run_on)');
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->dropColumn('run_day');
        });
    }
};
