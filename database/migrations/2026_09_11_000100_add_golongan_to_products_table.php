<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stream a part came through: impor, titip impor, or lokal.
 *
 * A third axis on the catalogue beside merk and kategori — see
 * `App\Domain\Catalogue\Golongan` for why it is its own column and why it is
 * nullable rather than defaulted. Indexed because the sales report groups on
 * it and the catalogue filters by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('golongan', 20)->nullable()->after('kategori');
            $table->index('golongan');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['golongan']);
            $table->dropColumn('golongan');
        });
    }
};
