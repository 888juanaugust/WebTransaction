<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where goods come from.
 *
 * Deliberately thin. This is not a purchasing module — there are no purchase
 * orders, no supplier bills, no accounts payable. It exists because a goods
 * receipt has to say where the stock came from, and "somewhere" is not an
 * answer anybody can reconcile against a supplier invoice later.
 *
 * Note this is unrelated to price_list_imports, which handles the *price list*
 * a supplier sends us. That is what we sell at; this is who we buy from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama');

            $table->string('nama_kontak')->nullable();
            $table->string('telepon', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->string('npwp', 25)->nullable();

            $table->boolean('aktif')->default(true);
            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index('aktif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
