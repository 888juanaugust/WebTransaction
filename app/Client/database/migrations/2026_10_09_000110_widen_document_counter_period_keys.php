<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A counter row of a branched series is keyed "JKT:202610": wider than the base's period key. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_counters', function (Blueprint $table) {
            $table->string('period_key', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_counters', function (Blueprint $table) {
            $table->string('period_key', 8)->change();
        });
    }
};
