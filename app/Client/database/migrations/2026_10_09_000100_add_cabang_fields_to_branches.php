<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A branch is a cabang: a short code that document numbers carry, and where it stands on the map. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('code', 8)->nullable()->unique()->after('name');
            $table->decimal('latitude', 9, 6)->nullable()->after('address');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['code', 'latitude', 'longitude']);
        });
    }
};
