<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\PriceTier;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Customers, from a spreadsheet instead of one form at a time.
 *
 * Built to the same rule as the price list: **reading is separate from
 * writing**. The file is parsed into a preview that says what each line would
 * do and why, a person looks at it, and only then does anything reach the
 * register. An importer that writes as it reads gives you half a customer
 * list and an error message.
 *
 * Three decisions worth keeping:
 *
 * **A known KODE updates rather than duplicates.** The register is keyed on
 * it, and the realistic file is last month's export with three rows added and
 * two phone numbers corrected. Refusing every existing code would make the
 * routine case impossible; creating a second row would silently split a
 * customer's history in two.
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
 * Owner, and a CSV must not be the way around that. A file carrying the
 * column is refused outright for anybody else, rather than quietly ignoring
 * the values they typed — they would never know their limits were dropped.
 */
class CompanyImporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Read the file and say what it would do. Writes nothing.
     *
     * @return list<CompanyImportRow>
     */
    public function preview(string $contents, User $actor): array
    {
        $this->assertMayImport($actor);

        $lines = $this->lines($contents);

        if ($lines === []) {
            throw new DomainException('Berkasnya kosong.');
        }

        $header = $this->header(array_shift($lines));
        $mayCredit = $actor->role()->canOverrideCreditLimit();

        if (! $mayCredit && $this->carriesCredit($header, $lines)) {
            throw new DomainException(
                'Berkas ini berisi kolom LIMIT_KREDIT yang terisi. Hanya Keuangan atau '
                .'Pemilik yang boleh menetapkan limit kredit — minta mereka yang mengimpor, '
                .'atau kosongkan kolom itu.'
            );
        }

        $existing = Company::query()->pluck('id', 'kode');
        $tiers = PriceTier::query()->pluck('id', 'nama');

        $rows = [];
        $seen = [];
        $nomor = 1; // the header was line 1

        foreach ($lines as $line) {
            $nomor++;

            if ($this->blank($line)) {
                continue;
            }

            $rows[] = $this->row($nomor, $this->cells($line, $header), $existing, $tiers, $seen, $mayCredit);
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
     * @param  array<string, int>  $header  column → position
     * @param  array<string, int>  $existing
     * @param  array<string, int>  $tiers
     * @param  array<string, true>  $seen
     */
    private function row(
        int $nomor,
        array $cells,
        $existing,
        $tiers,
        array &$seen,
        bool $mayCredit,
    ): CompanyImportRow {
        $alasan = [];
        $catatan = [];

        $kode = trim((string) ($cells['KODE'] ?? ''));
        $nama = trim((string) ($cells['NAMA'] ?? ''));

        if ($kode === '') {
            $alasan[] = 'KODE kosong';
        } elseif (isset($seen[$kode])) {
            $alasan[] = "KODE {$kode} muncul dua kali di berkas ini";
        }

        if ($nama === '') {
            $alasan[] = 'NAMA kosong';
        }

        $jenis = strtolower(trim((string) ($cells['JENIS_USAHA'] ?? '')));
        $jenisSah = ['bengkel', 'toko_sparepart', 'distributor'];

        if ($jenis === '') {
            $alasan[] = 'JENIS_USAHA kosong';
        } elseif (! in_array($jenis, $jenisSah, true)) {
            $alasan[] = "JENIS_USAHA '{$jenis}' tidak dikenal (bengkel, toko_sparepart, distributor)";
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
            'nama_wajib_pajak' => $this->teks($cells, 'NAMA_WAJIB_PAJAK'),
            'alamat_pajak' => $this->teks($cells, 'ALAMAT_PAJAK'),
            'catatan' => $this->teks($cells, 'CATATAN'),
        ];

        // Tier: named, not numbered — a person filling this in knows "Bengkel",
        // not that it is row 3 of price_tiers.
        $tier = trim((string) ($cells['TIER'] ?? ''));

        if ($tier !== '') {
            $id = $tiers[$tier] ?? null;

            if ($id === null) {
                $alasan[] = "TIER '{$tier}' tidak ada";
            } else {
                $nilai['price_tier_id'] = $id;
            }
        }

        $limit = $this->angka($cells['LIMIT_KREDIT'] ?? '');

        if ($limit === false) {
            $alasan[] = 'LIMIT_KREDIT bukan angka';
        } elseif ($limit !== null && $mayCredit) {
            $nilai['credit_limit_rupiah'] = $limit;
        }

        $tempo = $this->angka($cells['TEMPO_HARI'] ?? '');

        if ($tempo === false) {
            $alasan[] = 'TEMPO_HARI bukan angka';
        } elseif ($tempo !== null) {
            $nilai['payment_terms_days'] = $tempo;
        }

        $status = strtolower(trim((string) ($cells['STATUS'] ?? '')));
        $petaStatus = [
            '' => Company::STATUS_PENDING,
            'menunggu' => Company::STATUS_PENDING,
            'aktif' => Company::STATUS_ACTIVE,
            'ditangguhkan' => Company::STATUS_SUSPENDED,
        ];

        if (! array_key_exists($status, $petaStatus)) {
            $alasan[] = "STATUS '{$status}' tidak dikenal (aktif, menunggu, ditangguhkan)";
        } else {
            $nilai['status'] = $petaStatus[$status];

            if ($status === '') {
                $catatan[] = 'STATUS kosong — masuk sebagai menunggu persetujuan';
            }
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

    /** @return array<string, int> column name → position */
    private function header(string $line): array
    {
        $cells = str_getcsv($line, $this->delimiter($line), '"', '\\');
        $map = [];

        foreach ($cells as $position => $cell) {
            $name = strtoupper(trim((string) $cell));
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;

            if ($name !== '') {
                $map[$name] = $position;
            }
        }

        foreach (['KODE', 'NAMA', 'JENIS_USAHA'] as $wajib) {
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

    private function blank(string $line): bool
    {
        return trim(str_replace([',', ';', '"'], '', $line)) === '';
    }

    /** @param array<string, int> $header */
    private function carriesCredit(array $header, array $lines): bool
    {
        if (! array_key_exists('LIMIT_KREDIT', $header)) {
            return false;
        }

        foreach ($lines as $line) {
            if ($this->blank($line)) {
                continue;
            }

            if (trim($this->cells($line, $header)['LIMIT_KREDIT'] ?? '') !== '') {
                return true;
            }
        }

        return false;
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
