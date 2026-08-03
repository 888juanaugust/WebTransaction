<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('company_id')->constrained('companies');

            // Tax identity snapshot, taken when the invoice is issued. The
            // faktur must show what was true then, not what the customer
            // record says today.
            $table->string('npwp', 25)->nullable();
            $table->string('nama_wajib_pajak')->nullable();
            $table->text('alamat_pajak')->nullable();

            // Summed from the per-line snapshots. BIGINT rupiah.
            $table->bigInteger('subtotal_rupiah')->default(0);
            $table->bigInteger('discount_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->bigInteger('total_rupiah')->default(0);

            $table->date('issued_on');
            $table->date('due_date');

            // open | paid | void
            $table->string('status', 20)->default('open');

            // Coretax. Code 04 for ordinary goods under PMK 131/2024.
            $table->string('kode_transaksi', 2)->default('04');
            $table->string('nsfp', 30)->nullable();
            $table->timestamp('faktur_exported_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
