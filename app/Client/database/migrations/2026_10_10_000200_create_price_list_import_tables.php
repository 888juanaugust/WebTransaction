<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An uploaded price list on its way to becoming a version: the raw file, its parsed rows with their issues, and the diff against the list in force. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename', 255);
            $table->string('stored_path', 255);
            $table->string('checksum', 64)->nullable();
            $table->string('format', 12)->default('canonical'); // canonical | supplier
            $table->boolean('is_full_replacement')->default(false);
            $table->date('effective_from')->nullable();
            $table->string('status', 20)->default('uploaded'); // uploaded | parsing | parsed | failed | published | discarded
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('blocker_count')->default(0);
            $table->unsignedInteger('note_count')->default(0);
            $table->jsonb('diff')->nullable();
            $table->text('parse_error')->nullable();
            $table->foreignId('version_id')->nullable()->constrained('price_list_versions')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('brake_acknowledgement')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('price_list_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('price_list_imports')->cascadeOnDelete();
            $table->string('sheet', 100)->nullable();
            $table->unsignedInteger('row_number');
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
            $table->string('status', 10)->default('ok'); // ok | note | blocker
            $table->jsonb('issues')->nullable();
            $table->string('diff_bucket', 20)->nullable();
            $table->bigInteger('harga_lama')->nullable();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->timestamps();
            $table->index(['import_id', 'status']);
            $table->index(['import_id', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_import_rows');
        Schema::dropIfExists('price_list_imports');
    }
};
