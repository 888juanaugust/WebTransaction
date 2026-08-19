<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktiva tetap — the van, the racking, the computers.
 *
 * Two things were wrong without this, and both understate how the business is
 * actually doing:
 *
 *   - The neraca showed no fixed assets at all. Buying a delivery van went
 *     nowhere, because the only screen that could record money going out was
 *     the expense form, and that refuses non-expense accounts on purpose.
 *   - The laba rugi carried no depreciation, so profit was overstated by the
 *     whole wear on everything owned. That is the figure PPh is calculated on,
 *     which makes it the expensive kind of wrong.
 *
 * The register is the subledger for two control accounts, checked the same way
 * every other pair in this system is: Aktiva Tetap must equal the cost of the
 * assets still held, and Akumulasi Penyusutan must equal the depreciation
 * posted against them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->string('nama');
            $table->text('keterangan')->nullable();

            /*
             * The tax group, per UU PPh Pasal 11 — see DepreciationGroup. The
             * months are derived from it and stored anyway: an asset's life is
             * a fact about that asset as at the day it was registered, and a
             * later change to the enum must not silently re-depreciate things
             * bought years ago.
             */
            $table->string('kelompok', 30);
            $table->unsignedSmallInteger('masa_manfaat_bulan');

            $table->date('tanggal_perolehan');
            $table->bigInteger('harga_perolehan_rupiah');

            /*
             * Residual value. Zero by default, which is what Indonesian tax
             * depreciation assumes — Pasal 11 writes an asset down to nil over
             * its group life rather than to a salvage figure. Kept as a column
             * because book policy may differ and because a nil default that
             * cannot be overridden is a decision disguised as an absence.
             */
            $table->bigInteger('nilai_residu_rupiah')->default(0);

            // kendaraan | peralatan | bangunan | lainnya — for grouping the
            // register only. All of them post to the same pair of accounts;
            // splitting the chart by class is a chart change, not a code one.
            $table->string('kategori', 20)->default('lainnya');

            // aktif | dilepas
            $table->string('status', 20)->default('aktif');

            $table->string('dibayar_dari', 10);

            $table->date('tanggal_pelepasan')->nullable();
            $table->bigInteger('harga_jual_rupiah')->nullable();
            $table->string('alasan_pelepasan')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('disposed_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['status', 'tanggal_perolehan']);
        });

        /*
         * One month's depreciation on one asset.
         *
         * `(fixed_asset_id, periode)` is unique, and that uniqueness is the
         * whole safety of the monthly run: depreciation is a job somebody
         * triggers, and the failure that costs money is running it twice for
         * August. The ledger's own idempotency does not cover this on its own —
         * each row is its own source, so two rows would be two entries.
         *
         * `periode` is 'YYYY-MM' rather than a date, because that is what it
         * means. A depreciation charge belongs to a month, not to the day
         * somebody happened to press the button.
         */
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();

            $table->string('periode', 7);
            $table->date('tanggal');
            $table->bigInteger('amount_rupiah');

            /*
             * The book value left after this charge. Stored rather than
             * recomputed so the register can be read as a history, and so the
             * last month's rounding adjustment is visible where it happened
             * instead of being inferred.
             */
            $table->bigInteger('nilai_buku_setelah_rupiah');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->unique(['fixed_asset_id', 'periode']);
            $table->index('periode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
    }
};
