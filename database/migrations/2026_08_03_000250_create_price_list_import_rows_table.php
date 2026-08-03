<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('price_list_imports')->cascadeOnDelete();

            // Where in the workbook this came from, so a reviewer can find it.
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();

            // The cells exactly as read, before any interpretation.
            $table->jsonb('raw')->nullable();

            $table->string('kode', 60)->nullable();
            $table->string('merk', 40)->nullable();
            $table->string('kategori', 60)->nullable();
            $table->string('tipe_produk', 100)->nullable();
            $table->string('mobil', 150)->nullable();
            $table->string('part_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('qty_per_ctn')->nullable();
            $table->string('satuan_dasar', 10)->nullable();
            $table->bigInteger('harga')->nullable();
            $table->boolean('aktif')->default(true);
            $table->text('catatan')->nullable();

            // ok | note | blocker — blockers route to the review queue and are
            // never published; notes import anyway, annotated.
            $table->string('status', 10)->default('ok');
            $table->jsonb('issues')->nullable();

            // Which of the five diff buckets this row lands in.
            $table->string('diff_bucket', 20)->nullable();
            $table->bigInteger('harga_lama')->nullable();

            $table->timestamps();

            $table->index(['import_id', 'status']);
            $table->index(['import_id', 'diff_bucket']);
            $table->index(['import_id', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_import_rows');
    }
};
