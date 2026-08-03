<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            // KODE is the primary key. One row = one KODE. Everything that
            // refers to a product refers to it by `sku`, which is this value.
            $table->string('kode', 60)->primary();

            $table->string('merk', 40);
            $table->string('kategori', 60)->nullable();
            $table->string('tipe_produk', 100)->nullable();
            $table->string('mobil', 150)->nullable();
            $table->string('part_number', 100)->nullable();
            $table->text('description')->nullable();

            // Unit of measure is modeled, not assumed. Base unit is what the
            // stock ledger counts in; qty_per_ctn converts a carton to base.
            $table->unsignedInteger('qty_per_ctn')->default(1);
            $table->string('satuan_dasar', 10)->default('PCS');

            $table->boolean('aktif')->default(true);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('merk');
            $table->index('kategori');
            $table->index('part_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
