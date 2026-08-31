<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * There is no "Pusat" — the business runs from Surabaya.
 *
 * The region migration seeded one region called PST/Pusat because something
 * had to own the existing rows before `region_id` went NOT NULL. That was a
 * placeholder nobody chose; the testers pointed out it names a branch that
 * does not exist.
 *
 * Two rules make this safe to run on a database that has already been used:
 *
 * **Only the untouched placeholder is renamed.** If somebody has already
 * given the region its own name through the Wilayah screen, that is a
 * decision and this migration leaves it alone.
 *
 * **The kode only moves while it is still unprinted.** A region's kode goes
 * into every document number it issues — SO-PST-202608-0004 — and those
 * numbers are stored on the documents, quoted to customers, and reported to
 * the DJP. So the kode changes only when `document_counters` shows the region
 * has never issued a number. Where numbers exist, the name still becomes
 * Surabaya and the kode stays PST: mixed prefixes in one register are
 * confusing, but rewriting history is worse, and the real cutover runs on an
 * empty database (docs/GO-LIVE.md) where this migration renames both.
 */
return new class extends Migration
{
    public function up(): void
    {
        $region = DB::table('regions')
            ->where('kode', 'PST')
            ->where('nama', 'Pusat')
            ->first();

        if ($region === null) {
            return; // already named, or never was the placeholder
        }

        $sudahTerpakai = DB::table('document_counters')
            ->where('region_id', $region->id)
            ->exists();

        // Refuse to collide with a real SBY somebody already created.
        $bentrok = DB::table('regions')->where('kode', 'SBY')->exists();

        DB::table('regions')->where('id', $region->id)->update(array_filter([
            'kode' => $sudahTerpakai || $bentrok ? null : 'SBY',
            'nama' => 'Surabaya',
            'updated_at' => now(),
        ], fn ($value) => $value !== null));
    }

    /**
     * Reversing renames it back, but only if it still looks like what this
     * migration produced — the same courtesy on the way down as on the way up.
     */
    public function down(): void
    {
        DB::table('regions')
            ->where('nama', 'Surabaya')
            ->whereIn('kode', ['SBY', 'PST'])
            ->update(['kode' => 'PST', 'nama' => 'Pusat', 'updated_at' => now()]);
    }
};
