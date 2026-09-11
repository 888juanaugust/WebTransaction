<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each cabang stands on the map.
 *
 * Two numbers the Owner copies from a map, so the public contact page can
 * tell a visitor which branch is nearest — worked out in their browser
 * against this list, never by sending their position to us. Nullable: a
 * branch with no coordinates is still listed, just never "nearest".
 *
 * Six decimals is about a tenth of a metre, which is more than a street
 * address deserves and costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->decimal('lintang', 9, 6)->nullable()->after('telepon');
            $table->decimal('bujur', 9, 6)->nullable()->after('lintang');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn(['lintang', 'bujur']);
        });
    }
};
