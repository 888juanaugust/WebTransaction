<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama');

            // bengkel | toko_sparepart | distributor
            $table->string('jenis_usaha', 30)->default('bengkel');

            // Tax identity, as it must appear on the faktur pajak.
            $table->string('npwp', 25)->nullable();
            $table->string('nama_wajib_pajak')->nullable();
            $table->text('alamat_pajak')->nullable();

            $table->text('alamat_kirim')->nullable();
            $table->string('kota', 100)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('nama_kontak')->nullable();

            $table->foreignId('price_tier_id')->nullable()->constrained('price_tiers');

            // Money is BIGINT rupiah. Never float, never DECIMAL for totals.
            $table->bigInteger('credit_limit_rupiah')->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);

            // pending_approval | active | suspended
            $table->string('status', 20)->default('pending_approval');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
