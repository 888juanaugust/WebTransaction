<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Models\BankStatementLine;
use Carbon\Exceptions\InvalidFormatException;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Reads the CSV a bank portal exports, without trusting it to be tidy.
 *
 * There is no single Indonesian bank statement format. BCA, Mandiri and BRI
 * each export their own column names, their own delimiter, their own date
 * shape, and their own idea of how to write a number — sometimes in the same
 * file the same bank exported last month. So this parser makes the same bet
 * the price-list importer does: recognise columns by what their headers *mean*
 * rather than demanding an exact layout, and record every row it cannot read
 * as an error row instead of aborting the file or, worse, skipping it quietly.
 *
 * Two column layouts are understood:
 *
 *   - separate DEBIT and KREDIT columns (most portals) — on a bank statement
 *     these are the *bank's* seat, so KREDIT is money arriving in the account
 *     and DEBIT is money leaving it;
 *   - one MUTASI amount column plus a DB/CR flag column.
 *
 * Amounts survive `1.234.567,89`, `1,234,567.89`, `1234567.89`, a leading
 * `Rp`, and trailing `DB`/`CR` markers. Everything is rounded to whole rupiah
 * at the line — the ledger is BIGINT rupiah and a statement is not the place
 * fractional money enters the system.
 */
class StatementParser
{
    private const HEADER_TANGGAL = ['tanggal', 'tgl', 'date', 'post date', 'posting date', 'tanggal transaksi'];

    private const HEADER_URAIAN = ['uraian', 'keterangan', 'description', 'remark', 'deskripsi', 'uraian transaksi', 'transaksi'];

    private const HEADER_DEBIT = ['debit', 'debet', 'db', 'penarikan', 'keluar', 'withdrawal'];

    private const HEADER_KREDIT = ['kredit', 'credit', 'cr', 'setoran', 'masuk', 'deposit'];

    private const HEADER_MUTASI = ['mutasi', 'jumlah', 'amount', 'nominal', 'nilai'];

    private const HEADER_ARAH = ['db/cr', 'd/k', 'dk', 'cr/db', 'jenis', 'entry'];

    private const HEADER_SALDO = ['saldo', 'balance', 'saldo akhir'];

    public function parse(string $contents): ParsedStatement
    {
        // Strip a BOM and normalise line endings before anything looks at
        // the bytes — Windows exports carry both.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $rawLines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        $delimiter = $this->detectDelimiter($rawLines);

        [$map, $headerIndex] = $this->findHeader($rawLines, $delimiter);

        $rows = [];
        $errors = [];
        $urutan = 0;

        foreach (array_slice($rawLines, $headerIndex + 1) as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $cells = str_getcsv($raw, $delimiter, '"', '\\');
            $urutan++;

            $uraian = $this->cell($cells, $map['uraian'] ?? null) ?? '';

            try {
                $rows[] = $this->parseRow($cells, $map, $urutan, $uraian);
            } catch (SkipRow) {
                // A repeated header, a totals footer, an empty movement row —
                // noise the bank printed, not a transaction. Not an error.
                $urutan--;
            } catch (DomainException $e) {
                $errors[] = [
                    'urutan' => $urutan,
                    'uraian' => $uraian !== '' ? $uraian : trim($raw),
                    'sebab' => $e->getMessage(),
                ];
            }
        }

        return new ParsedStatement($rows, $errors);
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $map
     * @return array{urutan: int, tanggal: string, uraian: string, arah: string, amount_rupiah: int, saldo_rupiah: ?int}
     */
    private function parseRow(array $cells, array $map, int $urutan, string $uraian): array
    {
        $tanggalRaw = $this->cell($cells, $map['tanggal'] ?? null);

        // A repeated header row mid-file names its own columns again.
        if ($tanggalRaw !== null && $this->matches($tanggalRaw, self::HEADER_TANGGAL)) {
            throw new SkipRow;
        }

        try {
            [$arah, $amount] = $this->amountOf($cells, $map);
        } catch (DomainException $e) {
            // A totals footer fills both columns and carries no date — noise.
            // The same shape *with* a real date is corrupt data and stays an
            // error, because a transaction row cannot move both ways at once.
            try {
                $this->parseDate($tanggalRaw);
            } catch (DomainException) {
                throw new SkipRow;
            }

            throw $e;
        }

        if ($amount === null) {
            // No movement at all: an opening-balance banner or totals footer.
            throw new SkipRow;
        }

        if ($amount <= 0) {
            throw new DomainException('Nilai mutasi harus lebih dari nol.');
        }

        $tanggal = $this->parseDate($tanggalRaw);

        $saldoRaw = $this->cell($cells, $map['saldo'] ?? null);
        $saldo = $saldoRaw === null ? null : $this->parseAmount($saldoRaw);

        return [
            'urutan' => $urutan,
            'tanggal' => $tanggal->toDateString(),
            'uraian' => $uraian !== '' ? $uraian : '(tanpa uraian)',
            'arah' => $arah,
            'amount_rupiah' => $amount,
            'saldo_rupiah' => $saldo,
        ];
    }

    /**
     * The movement on a row, whichever layout the bank chose.
     *
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $map
     * @return array{0: string, 1: ?int}
     */
    private function amountOf(array $cells, array $map): array
    {
        if (isset($map['debit']) || isset($map['kredit'])) {
            $debit = $this->parseAmount($this->cell($cells, $map['debit'] ?? null));
            $kredit = $this->parseAmount($this->cell($cells, $map['kredit'] ?? null));

            if ($debit !== null && $debit > 0 && $kredit !== null && $kredit > 0) {
                throw new DomainException('Baris berisi debit dan kredit sekaligus.');
            }

            // The statement is the bank's book: its kredit is our money in.
            if ($kredit !== null && $kredit !== 0) {
                return [BankStatementLine::ARAH_MASUK, $kredit];
            }

            if ($debit !== null && $debit !== 0) {
                return [BankStatementLine::ARAH_KELUAR, $debit];
            }

            return [BankStatementLine::ARAH_MASUK, null];
        }

        $mutasiRaw = $this->cell($cells, $map['mutasi'] ?? null);
        $amount = $this->parseAmount($mutasiRaw);

        if ($amount === null || $amount === 0) {
            return [BankStatementLine::ARAH_MASUK, null];
        }

        $arah = $this->arahOf($mutasiRaw ?? '', $this->cell($cells, $map['arah'] ?? null), $amount);

        return [$arah, abs($amount)];
    }

    /** DB/CR flag column, a trailing marker on the amount, or the sign. */
    private function arahOf(string $mutasiRaw, ?string $flag, int $amount): string
    {
        $flag = strtoupper(trim((string) $flag));

        if (in_array($flag, ['CR', 'C', 'K', 'KREDIT', 'CREDIT'], true)) {
            return BankStatementLine::ARAH_MASUK;
        }

        if (in_array($flag, ['DB', 'D', 'DEBIT', 'DEBET'], true)) {
            return BankStatementLine::ARAH_KELUAR;
        }

        if (preg_match('/\bCR\b/i', $mutasiRaw)) {
            return BankStatementLine::ARAH_MASUK;
        }

        if (preg_match('/\bDB\b/i', $mutasiRaw)) {
            return BankStatementLine::ARAH_KELUAR;
        }

        return $amount < 0
            ? BankStatementLine::ARAH_KELUAR
            : BankStatementLine::ARAH_MASUK;
    }

    private function parseDate(?string $raw): Carbon
    {
        $raw = trim((string) $raw, " \t\"'");

        if ($raw === '') {
            throw new DomainException('Tanggal kosong.');
        }

        // dd/mm/yyyy and friends — the ambiguous formats are read the
        // Indonesian way, day before month, because that is who exported this.
        // Round-tripping the format back catches Carbon's overflow leniency
        // (32/01 must not quietly become 01/02).
        foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'd-m-y', 'Y-m-d', 'd M Y', 'd M y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $raw);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $parsed;
            }
        }

        throw new DomainException("Tanggal '{$raw}' tidak dikenali.");
    }

    /**
     * `1.234.567,89`, `1,234,567.89`, `1234567`, `Rp 500.000`, `250.000 CR`,
     * `(1.000)` for negative — all to whole rupiah. Null when the cell is
     * empty or carries no digits at all.
     */
    private function parseAmount(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        if ($raw === '' || ! preg_match('/\d/', $raw)) {
            return null;
        }

        $negative = str_starts_with($raw, '-') || str_starts_with($raw, '(');

        $s = preg_replace('/[^\d.,]/', '', $raw) ?? '';

        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present: whichever comes last is the decimal separator.
            $decimalSep = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastComma !== false) {
            // Only commas: decimal if exactly one with ≤2 digits after it
            // (Indonesian `,50`); otherwise thousands.
            $decimalSep = (substr_count($s, ',') === 1 && strlen($s) - $lastComma <= 3) ? ',' : null;
        } elseif ($lastDot !== false) {
            // Only dots: Indonesian exports use them as thousands (`500.000`),
            // so a dot is decimal only when what follows could not be a
            // thousands group.
            $decimalSep = (substr_count($s, '.') === 1 && strlen($s) - $lastDot - 1 !== 3) ? '.' : null;
        } else {
            $decimalSep = null;
        }

        if ($decimalSep !== null) {
            $pos = strrpos($s, $decimalSep);
            $whole = substr($s, 0, (int) $pos);
            $frac = substr($s, (int) $pos + 1);
        } else {
            $whole = $s;
            $frac = '';
        }

        $whole = preg_replace('/[^\d]/', '', $whole) ?? '';
        $frac = preg_replace('/[^\d]/', '', $frac) ?? '';

        if ($whole === '' && $frac === '') {
            return null;
        }

        $value = (float) (($whole === '' ? '0' : $whole).'.'.($frac === '' ? '0' : $frac));
        $value = (int) round($value);

        return $negative ? -$value : $value;
    }

    /**
     * Find the header row and map the columns it names.
     *
     * @param  list<string>  $rawLines
     * @return array{0: array<string, int>, 1: int}
     */
    private function findHeader(array $rawLines, string $delimiter): array
    {
        foreach (array_slice($rawLines, 0, 25, true) as $index => $raw) {
            $cells = str_getcsv($raw, $delimiter, '"', '\\');
            $map = [];

            foreach ($cells as $col => $cell) {
                $cell = strtolower(trim((string) $cell));

                foreach ([
                    'tanggal' => self::HEADER_TANGGAL,
                    'uraian' => self::HEADER_URAIAN,
                    'debit' => self::HEADER_DEBIT,
                    'kredit' => self::HEADER_KREDIT,
                    'mutasi' => self::HEADER_MUTASI,
                    'arah' => self::HEADER_ARAH,
                    'saldo' => self::HEADER_SALDO,
                ] as $key => $names) {
                    if (! isset($map[$key]) && in_array($cell, $names, true)) {
                        $map[$key] = $col;
                        break;
                    }
                }
            }

            $hasAmount = isset($map['debit']) || isset($map['kredit']) || isset($map['mutasi']);

            if (isset($map['tanggal']) && $hasAmount) {
                return [$map, $index];
            }
        }

        throw new DomainException(
            'Tidak menemukan baris judul kolom. Berkas harus CSV dengan kolom '
            .'tanggal dan mutasi (debit/kredit, atau jumlah + DB/CR).'
        );
    }

    /** @param  list<string>  $rawLines */
    private function detectDelimiter(array $rawLines): string
    {
        $sample = implode("\n", array_slice($rawLines, 0, 25));

        $best = ',';
        $bestCount = substr_count($sample, ',');

        foreach ([';', "\t"] as $candidate) {
            $count = substr_count($sample, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @param  list<string|null>  $cells */
    private function cell(array $cells, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $cells)) {
            return null;
        }

        $value = trim((string) $cells[$index]);

        return $value === '' ? null : $value;
    }

    private function matches(string $cell, array $names): bool
    {
        return in_array(strtolower(trim($cell)), $names, true);
    }
}
