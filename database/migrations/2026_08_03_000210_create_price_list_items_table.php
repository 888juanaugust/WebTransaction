<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('price_list_versions')->cascadeOnDelete();
            $table->string('kode', 60);

            // BIGINT rupiah. A price is never UPDATEd — a new version is inserted.
            $table->bigInteger('harga');

            $table->unsignedInteger('qty_per_ctn')->default(1);
            $table->boolean('aktif')->default(true);
            $table->timestamps();

            $table->unique(['version_id', 'kode']);
            $table->index('kode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
    }
};
