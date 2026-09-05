<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Audit\AuditLogger;
use App\Domain\Uom\Unit;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue from a spreadsheet, with a look before the leap.
 *
 * Built as the twin of `CompanyImporter` — same preview-then-write shape,
 * same three row statuses, same re-read on apply — because they are one idea
 * and a second idiom for it would only be a second thing to learn.
 *
 * **This is now the only way a SKU is created.** `ProductResource::canCreate`
 * returns false: the one-at-a-time form is gone, and every item enters
 * through this one validated path. That is worth more than the convenience
 * it costs — a SKU's `satuan_dasar` and `qty_per_ctn` are the arithmetic every
 * order and every stock movement runs through, and having a single door for
 * them means a single place to check them.
 *
 * **No prices.** `ProductColumns` has no HARGA and a file carrying one is
 * refused rather than ignored, because prices move by publishing a new price
 * list version and never by an update. Somebody who pastes a supplier price
 * list in here has made an understandable mistake, and the refusal names the
 * screen that does want it.
 *
 * **Inventori's seat, not everyone's.** Gated on `canManageCatalogue()` —
 * the same capability the catalogue's edit form asks for, so the CSV cannot
 * be a way around it.
 */
class ProductImporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Read the file and say what it would do. Writes nothing.
     *
     * @return list<ProductImportRow>
     */
    public function preview(string $contents, User $actor): array
    {
        $this->assertMayImport($actor);

        $lines = $this->lines($contents);

        if ($lines === []) {
            throw new DomainException('Berkasnya kosong.');
        }

        $header = $this->header(array_shift($lines));

        if (array_key_exists('HARGA', $header)) {
            throw new DomainException(
                'Berkas ini punya kolom HARGA. Impor barang hanya mengurus katalog — apa '
                .'barangnya, bukan berapa harganya. Harga diterbitkan lewat Impor harga & '
                .'barang, yang membuat versi daftar harga baru. Hapus kolom HARGA, atau '
                .'pakai layar itu.'
            );
        }

        // One query, not one per row: an eighty-line file is eighty lookups
        // otherwise, and the supplier workbook runs to thousands.
        $existing = Product::query()->pluck('kode', 'kode')->all();

        $brands = $this->normalised(config('pricelist.known_brands', []));
        $categories = $this->normalised(config('pricelist.known_categories', []));

        $rows = [];
        $seen = [];
        $nomor = 1; // the header was line 1

        foreach ($lines as $line) {
            $nomor++;

            if ($this->blank($line)) {
                continue;
            }

            $row = $this->row($nomor, $this->cells($line, $header), $existing, $brands, $categories, $seen);

            if ($row->kode !== '') {
                $seen[$row->kode] = true;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Write the rows that were not held back.
     *
     * Re-previewed from the same file rather than trusting what the screen
     * hands back: the preview a person approved and the rows that get written
     * must come from one reading of one file, or a tampered request could
     * write values nobody saw.
     *
     * @return array{baru: int, diperbarui: int, tertahan: int}
     */
    public function import(string $contents, User $actor, ?string $sumber = null): array
    {
        $rows = $this->preview($contents, $actor);

        $baru = 0;
        $diperbarui = 0;
        $tertahan = 0;

        DB::transaction(function () use ($rows, $actor, $sumber, &$baru, &$diperbarui, &$tertahan) {
            foreach ($rows as $row) {
                if ($row->tertahan()) {
                    $tertahan++;

                    continue;
                }

                $product = Product::query()->whereKey($row->kode)->first();

                if ($product === null) {
                    Product::query()->create($row->nilai);
                    $baru++;

                    continue;
                }

                $product->fill($row->nilai)->save();
                $diperbarui++;
            }

            $this->audit->log(
                action: 'products_imported',
                newValue: ['baru' => $baru, 'diperbarui' => $diperbarui, 'tertahan' => $tertahan,
                    'berkas' => $sumber],
                actor: $actor,
            );
        });

        return ['baru' => $baru, 'diperbarui' => $diperbarui, 'tertahan' => $tertahan];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $existing
     * @param  array<string, string>  $brands  normalised → canonical
     * @param  array<string, string>  $categories
     * @param  array<string, true>  $seen
     */
    private function row(
        int $nomor,
        array $cells,
        array $existing,
        array $brands,
        array $categories,
        array $seen,
    ): ProductImportRow {
        $alasan = [];
        $catatan = [];

        $kode = strtoupper($this->teks($cells, 'KODE'));
        $deskripsi = $this->teks($cells, 'DESCRIPTION');

        if ($kode === '') {
            $alasan[] = 'KODE kosong';
        } elseif (isset($seen[$kode])) {
            $alasan[] = "KODE {$kode} muncul dua kali di berkas ini";
        } elseif (str_contains($kode, '/')) {
            /*
             * The supplier workbook puts two SKUs in one cell separated by a
             * slash. The price list importer routes those to a review queue;
             * here there is no queue to route to, so the row is held and the
             * message says what to do — because a KODE with a slash in it
             * would otherwise become a product nobody can ever order.
             */
            $alasan[] = "KODE '{$kode}' berisi lebih dari satu kode — pisahkan jadi dua baris";
        }

        $merk = $this->pilihan($this->teks($cells, 'MERK'), $brands);

        if ($merk === null) {
            $alasan[] = $this->teks($cells, 'MERK') === ''
                ? 'MERK kosong'
                : "MERK '{$this->teks($cells, 'MERK')}' tidak dikenal";
        }

        $kategori = $this->pilihan($this->teks($cells, 'KATEGORI'), $categories);

        if ($kategori === null) {
            $alasan[] = $this->teks($cells, 'KATEGORI') === ''
                ? 'KATEGORI kosong'
                : "KATEGORI '{$this->teks($cells, 'KATEGORI')}' tidak dikenal";
        }

        /*
         * The base unit is invariant 5's hinge: the stock ledger counts in it
         * and every order converts through it. CTN is a valid Unit but never
         * a base unit — a product measured in cartons would make "one carton
         * of one carton" a coherent sentence.
         */
        $satuan = strtoupper($this->teks($cells, 'SATUAN_DASAR'));

        if ($satuan === '') {
            $alasan[] = 'SATUAN_DASAR kosong (PCS atau SET)';
        } elseif (! in_array($satuan, [Unit::Pcs->value, Unit::Set->value], true)) {
            $alasan[] = "SATUAN_DASAR '{$satuan}' bukan PCS atau SET";
        }

        $qty = $this->angka($this->teks($cells, 'QTY_PER_CTN'));

        if ($qty === false) {
            $alasan[] = 'QTY_PER_CTN bukan angka';
        } elseif ($qty === null) {
            // CLAUDE.md's rule for the supplier workbook's ~724 blanks:
            // import anyway, default to 1, and say so on the row.
            $qty = 1;
            $catatan[] = 'QTY_PER_CTN kosong — dianggap 1';
        } elseif ($qty < 1) {
            $alasan[] = 'QTY_PER_CTN harus 1 atau lebih';
        }

        $aktifRaw = strtoupper($this->teks($cells, 'AKTIF'));
        $aktif = match ($aktifRaw) {
            '', 'Y', 'YA', 'YES', '1', 'TRUE' => true,
            'N', 'TIDAK', 'NO', '0', 'FALSE' => false,
            default => null,
        };

        if ($aktif === null) {
            $alasan[] = "AKTIF '{$aktifRaw}' tidak dikenal (Y atau N)";
        }

        $sudahAda = $kode !== '' && array_key_exists($kode, $existing);

        /*
         * Changing the base unit of a SKU that already has stock movements
         * would silently re-denominate its whole history — 200 PCS becoming
         * 200 SET. Held rather than allowed, and the message names the way
         * out, which is a new SKU rather than an edit.
         */
        if ($sudahAda && $satuan !== '' && $alasan === []) {
            $product = Product::query()->whereKey($kode)->first();

            if ($product !== null && $product->satuan_dasar !== $satuan && $product->hasHistory()) {
                $alasan[] = sprintf(
                    'SATUAN_DASAR barang ini sudah %s dan tidak bisa diubah ke %s — '
                    .'riwayat stoknya dihitung dalam satuan itu. Buat kode baru.',
                    $product->satuan_dasar,
                    $satuan,
                );
            }
        }

        $nilai = [
            'kode' => $kode,
            'merk' => $merk,
            'kategori' => $kategori,
            'tipe_produk' => $this->teks($cells, 'TIPE_PRODUK'),
            'mobil' => $this->teks($cells, 'MOBIL'),
            'part_number' => $this->teks($cells, 'PART_NUMBER'),
            'description' => $deskripsi,
            'qty_per_ctn' => $qty === false ? 1 : $qty,
            'satuan_dasar' => $satuan,
            'aktif' => $aktif ?? true,
            'catatan' => $this->teks($cells, 'CATATAN'),
        ];

        $status = match (true) {
            $alasan !== [] => ProductImportRow::TERTAHAN,
            $sudahAda => ProductImportRow::PERBARUI,
            default => ProductImportRow::BARU,
        };

        return new ProductImportRow(
            baris: $nomor,
            kode: $kode,
            nama: $deskripsi,
            status: $status,
            nilai: $nilai,
            alasan: $alasan,
            catatan: $catatan,
        );
    }

    /**
     * Match a cell against the allowed values, ignoring case and spacing.
     *
     * "hydraulic part" and "HYDRAULIC  PART" are the same category typed by
     * two people; rejecting the second teaches nobody anything.
     *
     * @param  array<string, string>  $allowed  normalised → canonical
     */
    private function pilihan(string $raw, array $allowed): ?string
    {
        return $allowed[$this->key($raw)] ?? null;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function normalised(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            $out[$this->key($value)] = $value;
        }

        return $out;
    }

    private function key(string $raw): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim($raw)) ?? '');
    }

    /**
     * @return array<string, int> column → position
     */
    private function header(string $line): array
    {
        $map = [];

        foreach (str_getcsv($line, $this->delimiter($line), '"', '\\') as $position => $name) {
            $name = strtoupper(trim((string) $name));
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;

            if ($name !== '') {
                $map[$name] = $position;
            }
        }

        foreach (['KODE', 'MERK', 'KATEGORI', 'SATUAN_DASAR'] as $wajib) {
            if (! array_key_exists($wajib, $map)) {
                throw new DomainException(
                    "Baris judul tidak punya kolom {$wajib}. Unduh contoh CSV dari layar ini "
                    .'dan pakai baris judulnya apa adanya.'
                );
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $header
     * @return array<string, string>
     */
    private function cells(string $line, array $header): array
    {
        $cells = str_getcsv($line, $this->delimiter($line), '"', '\\');
        $out = [];

        foreach ($header as $name => $position) {
            $out[$name] = (string) ($cells[$position] ?? '');
        }

        return $out;
    }

    /** @return list<string> */
    private function lines(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        return array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contents) ?: [],
            fn (string $line) => trim($line) !== '',
        ));
    }

    /**
     * Comma or semicolon, whichever the line actually uses — Excel in an
     * Indonesian locale saves semicolons.
     */
    private function delimiter(string $line): string
    {
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function blank(string $line): bool
    {
        return trim(str_replace([',', ';', '"'], '', $line)) === '';
    }

    /** @param array<string, string> $cells */
    private function teks(array $cells, string $column): string
    {
        return trim((string) ($cells[$column] ?? ''));
    }

    /**
     * Null for blank, false for not-a-number — "leave it alone" and "this row
     * is wrong" are different answers and a cast to int cannot tell them apart.
     */
    private function angka(string $raw): int|false|null
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $bersih = str_replace(['.', ',', ' '], '', $raw);

        return ctype_digit($bersih) ? (int) $bersih : false;
    }

    private function assertMayImport(User $actor): void
    {
        if (! $actor->role()->canManageCatalogue()) {
            throw new DomainException('Peran ini tidak berhak mengelola katalog.');
        }
    }
}
