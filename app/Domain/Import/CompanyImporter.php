<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\PriceTier;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Customers, from a spreadsheet instead of one form at a time.
 *
 * Built to the same rule as the price list: **reading is separate from
 * writing**. The file is parsed into a preview that says what each line would
 * do and why, a person looks at it, and only then does anything reach the
 * register. An importer that writes as it reads gives you half a customer
 * list and an error message.
 *
 * Two files are accepted, and one set of rules judges both. The accounting
 * package's own customer workbook (CompanyWorkbookLayout — an .xlsx, or the
 * same columns saved as CSV) is what people actually have; it is translated
 * into the canonical columns first. A CSV in the canonical columns themselves
 * still imports. The layout is recognised from the heading row, never asked.
 *
 * Three decisions worth keeping:
 *
 * **A known KODE updates rather than duplicates.** The register is keyed on
 * it, and the realistic file is last month's export with three rows added and
 * two phone numbers corrected. Refusing every existing code would make the
 * routine case impossible; creating a second row would silently split a
 * customer's history in two. And a blank status on such a row leaves the
 * status alone — an update file is not an approval decision.
 *
 * **A bad row is held back, never guessed at.** No defaulting an unknown
 * jenis_usaha to bengkel, no rounding a price tier to the nearest name. The
 * row is listed with its reason and the rest of the file still imports —
 * which is what lets somebody fix six lines rather than re-upload eighty.
 *
 * The credit-limit audit trail is not written here: `CompanyObserver` already
 * logs that move on any update, from wherever it came, and a second call would
 * record one change as two. What this class logs is the import itself — how
 * many rows landed, how many were held, and which file they came from.
 *
 * **Credit limits need the seat that may set them.** `LIMIT_KREDIT` is a
 * money-affecting field: the customer form only shows it to Finance and the
 * Owner, and a spreadsheet must not be the way around that. A file carrying
 * the column is refused outright for anybody else, rather than quietly
 * ignoring the values they typed — they would never know their limits were
 * dropped.
 */
class CompanyImporter
{
    public const LAYOUT_KANONIK = 'kanonik';

    public const LAYOUT_WORKBOOK = 'workbook';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Read the file and say what it would do. Writes nothing.
     *
     * @return list<CompanyImportRow>
     */
    public function preview(string $contents, User $actor): array
    {
        $this->assertMayImport($actor);

        $table = $this->table($contents);

        if ($table === []) {
            throw new DomainException('Berkasnya kosong.');
        }

        $judul = array_map(fn ($c) => trim((string) $c), array_shift($table));
        $layout = CompanyWorkbookLayout::kenali($judul) ? self::LAYOUT_WORKBOOK : self::LAYOUT_KANONIK;

        // Every line as canonical cells first, so one rule set judges both layouts.
        $baris = $this->canonicalRows($layout, $judul, $table);

        $mayCredit = $actor->role()->canOverrideCreditLimit();

        if (! $mayCredit && $this->carriesCredit($baris)) {
            $label = $this->label($layout, 'LIMIT_KREDIT');

            throw new DomainException(
                "Berkas ini berisi kolom {$label} yang terisi. Hanya Keuangan atau "
                .'Pemilik yang boleh menetapkan limit kredit — minta mereka yang mengimpor, '
                .'atau kosongkan kolom itu.'
            );
        }

        $existing = Company::query()->pluck('id', 'kode');
        $tiers = PriceTier::query()->pluck('id', 'nama');

        $rows = [];
        $seen = [];

        foreach ($baris as [$nomor, $cells, $catatanAwal]) {
            $rows[] = $this->row($layout, $nomor, $cells, $catatanAwal, $existing, $tiers, $seen, $mayCredit);
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

                $company = Company::query()->where('kode', $row->kode)->first();

                if ($company === null) {
                    Company::query()->create($row->nilai);
                    $baru++;

                    continue;
                }

                /*
                 * Saving is enough to get the change audited: CompanyObserver
                 * logs a credit-limit move and a status change on any update,
                 * from wherever it came, and this is one of those wheres. An
                 * explicit call here as well would write the same override
                 * twice and make the log read as two changes.
                 */
                $company->fill($row->nilai)->save();
                $diperbarui++;
            }

            $this->audit->log(
                action: 'companies_imported',
                newValue: ['baru' => $baru, 'diperbarui' => $diperbarui, 'tertahan' => $tertahan,
                    'berkas' => $sumber],
                actor: $actor,
            );
        });

        return ['baru' => $baru, 'diperbarui' => $diperbarui, 'tertahan' => $tertahan];
    }

    /**
     * @param  array<string, string>  $cells  canonical column → value
     * @param  list<string>  $catatanAwal  notes the translation already made
     * @param  array<string, int>  $existing
     * @param  array<string, int>  $tiers
     * @param  array<string, true>  $seen
     */
    private function row(
        string $layout,
        int $nomor,
        array $cells,
        array $catatanAwal,
        $existing,
        $tiers,
        array &$seen,
        bool $mayCredit,
    ): CompanyImportRow {
        $alasan = [];
        $catatan = $catatanAwal;
        $label = fn (string $kolom): string => $this->label($layout, $kolom);

        $kode = trim((string) ($cells['KODE'] ?? ''));
        $nama = trim((string) ($cells['NAMA'] ?? ''));

        if ($kode === '') {
            $alasan[] = $label('KODE').' kosong';
        } elseif (isset($seen[$kode])) {
            $alasan[] = "{$label('KODE')} {$kode} muncul dua kali di berkas ini";
        }

        if ($nama === '') {
            $alasan[] = $label('NAMA').' kosong';
        }

        $jenis = strtolower(trim((string) ($cells['JENIS_USAHA'] ?? '')));
        $jenisSah = ['bengkel', 'toko_sparepart', 'distributor'];

        if ($jenis === '') {
            $alasan[] = $label('JENIS_USAHA').' kosong';
        } elseif (! in_array($jenis, $jenisSah, true)) {
            $alasan[] = "{$label('JENIS_USAHA')} '{$jenis}' tidak dikenal (bengkel, toko_sparepart, distributor)";
        }

        $nilai = [
            'kode' => $kode,
            'nama' => $nama,
            'jenis_usaha' => $jenis,
            'nama_kontak' => $this->teks($cells, 'NAMA_KONTAK'),
            'telepon' => $this->teks($cells, 'TELEPON'),
            'email' => $this->teks($cells, 'EMAIL'),
            'kota' => $this->teks($cells, 'KOTA'),
            'alamat_kirim' => $this->teks($cells, 'ALAMAT_KIRIM'),
            'npwp' => $this->teks($cells, 'NPWP'),
            'id_tku' => $this->teks($cells, 'ID_TKU') ?: null,
            'nama_wajib_pajak' => $this->teks($cells, 'NAMA_WAJIB_PAJAK'),
            'alamat_pajak' => $this->teks($cells, 'ALAMAT_PAJAK'),
            'catatan' => $this->teks($cells, 'CATATAN'),
        ];

        if ($nilai['id_tku'] !== null && strlen(preg_replace('/\D+/', '', $nilai['id_tku']) ?? '') !== 22) {
            $alasan[] = "{$label('ID_TKU')} '{$nilai['id_tku']}' bukan 22 digit";
        }

        // Tier: named, not numbered — a person filling this in knows "Bengkel",
        // not that it is row 3 of price_tiers.
        $tier = trim((string) ($cells['TIER'] ?? ''));

        if ($tier !== '') {
            $id = $tiers[$tier] ?? null;

            if ($id === null) {
                $alasan[] = "{$label('TIER')} '{$tier}' tidak ada";
            } else {
                $nilai['price_tier_id'] = $id;
            }
        }

        $limit = $this->angka($cells['LIMIT_KREDIT'] ?? '');

        if ($limit === false) {
            $alasan[] = $label('LIMIT_KREDIT').' bukan angka';
        } elseif ($limit !== null && $mayCredit) {
            $nilai['credit_limit_rupiah'] = $limit;
        }

        $tempo = $this->angka($cells['TEMPO_HARI'] ?? '');

        if ($tempo === false) {
            $alasan[] = $label('TEMPO_HARI').' bukan angka';
        } elseif ($tempo !== null) {
            $nilai['payment_terms_days'] = $tempo;
        }

        $status = strtolower(trim((string) ($cells['STATUS'] ?? '')));
        $petaStatus = [
            'menunggu' => Company::STATUS_PENDING,
            'aktif' => Company::STATUS_ACTIVE,
            'ditangguhkan' => Company::STATUS_SUSPENDED,
        ];

        if ($status === '') {
            /*
             * Blank means two different things. For a customer that is not
             * on the register yet it means nobody has approved them, and
             * they arrive awaiting approval — a customer that starts active
             * because a column was left blank is a customer nobody approved.
             * For a customer already on the register it means "no change":
             * an update file with a blank status is not a decision to send
             * an approved customer back to the queue.
             */
            if (isset($existing[$kode])) {
                $catatan[] = $label('STATUS').' kosong — status lama dipertahankan';
            } else {
                $nilai['status'] = Company::STATUS_PENDING;
                $catatan[] = $label('STATUS').' kosong — masuk sebagai menunggu persetujuan';
            }
        } elseif (! array_key_exists($status, $petaStatus)) {
            $alasan[] = "{$label('STATUS')} '{$status}' tidak dikenal (aktif, menunggu, ditangguhkan)";
        } else {
            $nilai['status'] = $petaStatus[$status];
        }

        if (($nilai['npwp'] ?? '') === '') {
            $catatan[] = 'Tanpa NPWP — faktur pajaknya belum bisa diekspor';
        }

        if ($alasan !== []) {
            return new CompanyImportRow($nomor, $kode, $nama, CompanyImportRow::TERTAHAN,
                alasan: $alasan, catatan: $catatan);
        }

        $seen[$kode] = true;

        return new CompanyImportRow(
            baris: $nomor,
            kode: $kode,
            nama: $nama,
            status: isset($existing[$kode]) ? CompanyImportRow::PERBARUI : CompanyImportRow::BARU,
            nilai: $nilai,
            catatan: $catatan,
        );
    }

    /**
     * Every data line as canonical cells, numbered as the person sees them
     * in their spreadsheet, blank lines skipped.
     *
     * @param  list<string>  $judul
     * @param  list<list<string>>  $table
     * @return list<array{0: int, 1: array<string, string>, 2: list<string>}>
     */
    private function canonicalRows(string $layout, array $judul, array $table): array
    {
        $rows = [];
        $nomor = 1; // the heading was line 1

        if ($layout === self::LAYOUT_WORKBOOK) {
            $posisi = CompanyWorkbookLayout::posisi($judul);

            foreach ($table as $line) {
                $nomor++;

                if ($this->blank($line)) {
                    continue;
                }

                [$cells, $catatan] = CompanyWorkbookLayout::keCanonical($posisi, $line);
                $rows[] = [$nomor, $cells, $catatan];
            }

            return $rows;
        }

        $header = $this->header($judul);

        foreach ($table as $line) {
            $nomor++;

            if ($this->blank($line)) {
                continue;
            }

            $rows[] = [$nomor, $this->cells($line, $header), []];
        }

        return $rows;
    }

    /**
     * The canonical heading row as column name → position.
     *
     * @param  list<string>  $judul
     * @return array<string, int>
     */
    private function header(array $judul): array
    {
        $map = [];

        foreach ($judul as $position => $cell) {
            $name = strtoupper(trim((string) $cell));
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;

            if ($name !== '') {
                $map[$name] = $position;
            }
        }

        foreach (['KODE', 'NAMA', 'JENIS_USAHA'] as $wajib) {
            if (! array_key_exists($wajib, $map)) {
                throw new DomainException(
                    "Baris judul tidak punya kolom {$wajib}. Unduh contoh dari layar ini "
                    .'dan pakai baris judulnya apa adanya — atau unggah workbook pelanggan '
                    .'dari ACCURATE (Template Impor Pelanggan).'
                );
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $line
     * @param  array<string, int>  $header
     * @return array<string, string>
     */
    private function cells(array $line, array $header): array
    {
        $out = [];

        foreach ($header as $name => $position) {
            $out[$name] = (string) ($line[$position] ?? '');
        }

        return $out;
    }

    /**
     * The file as rows of cells, whichever it is.
     *
     * An .xlsx starts with the zip signature and is read through
     * PhpSpreadsheet, first sheet only — the accounting package's template
     * says so itself, and its second sheet is the column legend. Anything
     * else is text, split on the delimiter the line actually uses.
     *
     * @return list<list<string>>
     */
    private function table(string $contents): array
    {
        if (str_starts_with($contents, "PK\x03\x04")) {
            return $this->fromXlsx($contents);
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = array_map(fn ($c) => (string) $c, str_getcsv($line, $this->delimiter($line), '"', '\\'));
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function fromXlsx(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'impor-pelanggan-');

        if ($path === false) {
            throw new DomainException('Tidak bisa membaca berkas Excel.');
        }

        try {
            file_put_contents($path, $contents);

            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);

            $rows = [];

            // Formatted, so a date cell reads 19/01/2016 and a postcode 14470,
            // the way the person sees them — not a serial and a float.
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $rows[] = array_map(fn ($c) => trim((string) ($c ?? '')), $row);
            }

            // Trailing empty rows are the sheet's, not the person's.
            while ($rows !== [] && $this->blank(end($rows))) {
                array_pop($rows);
            }

            return $rows;
        } catch (Throwable $e) {
            throw new DomainException('Berkas Excel tidak bisa dibaca: '.$e->getMessage(), previous: $e);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Comma or semicolon, whichever the line actually uses.
     *
     * Excel in an Indonesian locale saves CSV with semicolons, and a file
     * rejected for that reason reads as "the importer is broken" rather than
     * "your Excel disagrees with mine".
     */
    private function delimiter(string $line): string
    {
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /** @param list<string> $line */
    private function blank(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{0: int, 1: array<string, string>, 2: list<string>}> $baris */
    private function carriesCredit(array $baris): bool
    {
        foreach ($baris as [, $cells]) {
            if (trim((string) ($cells['LIMIT_KREDIT'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** The column's name as the person sees it in the file they uploaded. */
    private function label(string $layout, string $kolom): string
    {
        if ($layout === self::LAYOUT_WORKBOOK) {
            return CompanyWorkbookLayout::label()[$kolom] ?? $kolom;
        }

        return $kolom;
    }

    /** @param array<string, string> $cells */
    private function teks(array $cells, string $column): string
    {
        return trim((string) ($cells[$column] ?? ''));
    }

    /**
     * A rupiah figure, however it was typed.
     *
     * Returns null for blank, false for something that is not a number —
     * distinguishing "leave it alone" from "this row is wrong", which a
     * plain cast to int cannot do.
     */
    private function angka(string $raw): int|false|null
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // 50.000.000 and 50,000,000 are both what people type.
        $bersih = str_replace(['.', ',', ' ', 'Rp', 'rp'], '', $raw);

        return ctype_digit($bersih) ? (int) $bersih : false;
    }

    private function assertMayImport(User $actor): void
    {
        if (! $actor->role()->canSeeCreditData()) {
            throw new DomainException('Peran ini tidak berhak mengelola data pelanggan.');
        }
    }
}
