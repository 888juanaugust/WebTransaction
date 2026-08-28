<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wilayah — a region, and a complete set of books.
 *
 * Not a branch that reports upward into one ledger. Each region keeps its own
 * stock, its own customers and suppliers, its own journals, and closes its own
 * months. Nothing moves between them: no inter-region transfers, no shared
 * customers, no due-to/due-from accounts to keep in step. That was a deliberate
 * choice and it is what makes the whole thing tractable — the alternative,
 * regions trading with each other, turns every transfer into a transaction
 * between two ledgers that must always net to zero.
 *
 * What stays group-wide is the catalogue: products, the price list and its
 * versions. Head office sets what a part is and what it costs; a region decides
 * how many it holds and who it sells them to. Keeping the price list shared is
 * also what preserves the project's rule that pricing is one pure function —
 * a per-region price list would be a second place for a price to live.
 *
 * The tax identity is nullable on purpose. Two arrangements are both normal:
 * one PT with regional books kept apart for management, filing a single return
 * under one NPWP; or genuinely separate legal entities. Null means "use the
 * company-wide identity from config", so the first case needs no data entry and
 * the second is a matter of filling three fields in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();

            /*
             * Short, and it goes into every document number this region issues
             * — INV-JKT-202608-0001. That is what keeps numbers unique across
             * the group: without it two regions both issue INV-202608-0001 and
             * nobody can tell which one a customer is asking about.
             */
            $table->string('kode', 8)->unique();

            $table->string('nama');
            $table->text('alamat')->nullable();
            $table->string('telepon', 40)->nullable();

            // Null falls back to config('pajak.penjual'). See the note above.
            $table->string('npwp', 25)->nullable();
            $table->string('nama_wajib_pajak')->nullable();
            $table->text('alamat_pajak')->nullable();

            $table->boolean('aktif')->default(true);
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        /*
         * One region, immediately, because the next migration makes region_id
         * NOT NULL on thirty-two tables and every existing row has to belong
         * somewhere. Its code comes from the environment so a deployment that
         * already knows itself as JKT does not have to be renamed afterwards.
         */
        DB::table('regions')->insert([
            'kode' => env('WILAYAH_UTAMA_KODE', 'PST'),
            'nama' => env('WILAYAH_UTAMA_NAMA', 'Pusat'),
            'aktif' => true,
            'catatan' => 'Dibuat otomatis saat wilayah diaktifkan. '
                .'Semua data yang ada sebelumnya masuk ke sini.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
