<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunjungan toko — proof a sales actually stood in the store.
 *
 * The photo, the coordinates and the moment, captured on the phone at the
 * door. The photo lives on disk with its path here; the row outlives the
 * photo, because the 2-month retention deletes the heavy file but "was the
 * customer visited in March" stays answerable forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('sales_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // Where the phone said it was. Seven decimals is centimetre
            // precision — more than GPS gives, never less than it needs.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('foto_path')->nullable();
            $table->timestamp('foto_dihapus_pada')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamp('visited_at');

            $table->timestamps();

            $table->index(['sales_user_id', 'visited_at']);
            $table->index(['region_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_visits');
    }
};
