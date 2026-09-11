<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * The opening-balance formats: one for what customers owe us, one for what
 * we owe suppliers. Same six columns, different party.
 *
 * SISA, not the original total. What is being brought in is the debt as it
 * stands on the day of conversion; what it was before the old system took
 * some payments is the old system's business. One row per open document,
 * because that is what ages, gets chased and gets paid — a single lump per
 * customer would be a debt with no due date.
 */
final class SaldoAwalColumns
{
    /** @var list<string> */
    public const PIUTANG = ['NOMOR', 'PELANGGAN', 'TANGGAL', 'JATUH_TEMPO', 'SISA', 'CATATAN'];

    /** @var list<string> */
    public const HUTANG = ['NOMOR', 'PEMASOK', 'TANGGAL', 'JATUH_TEMPO', 'SISA', 'CATATAN'];

    /** @return array<string, string> */
    public static function keteranganPiutang(): array
    {
        return [
            'NOMOR' => 'Wajib, unik. Nomor faktur dari pembukuan lama — itu yang pelanggan kenal saat ditagih.',
            'PELANGGAN' => 'Wajib. Kode pelanggan seperti di daftar pelanggan (kolom KODE).',
            'TANGGAL' => 'Wajib. Tanggal faktur aslinya, 2026-08-15 atau 15/08/2026. Umur piutang dihitung dari sini.',
            'JATUH_TEMPO' => 'Tanggal jatuh tempo. Kosong: tanggal + tempo pelanggan itu.',
            'SISA' => 'Wajib. Rupiah yang masih terhutang hari ini, bukan nilai faktur aslinya. Angka bulat, boleh dengan titik ribuan.',
            'CATATAN' => 'Catatan bebas, mis. nomor referensi lama.',
            '(PPN)' => 'Tidak ada. PPN faktur ini sudah dilaporkan di pembukuan lama; di sini yang dibawa hanya piutangnya, dibukukan lawan Saldo Awal Konversi — bukan Penjualan.',
        ];
    }

    /** @return array<string, string> */
    public static function keteranganHutang(): array
    {
        return [
            'NOMOR' => 'Wajib, unik. Nomor faktur pemasok dari pembukuan lama.',
            'PEMASOK' => 'Wajib. Kode pemasok seperti di daftar pemasok (kolom KODE).',
            'TANGGAL' => 'Wajib. Tanggal faktur pemasok, 2026-08-15 atau 15/08/2026.',
            'JATUH_TEMPO' => 'Tanggal jatuh tempo. Kosong: tanggal + tempo pemasok itu.',
            'SISA' => 'Wajib. Rupiah yang masih kami hutang hari ini. Angka bulat, boleh dengan titik ribuan.',
            'CATATAN' => 'Catatan bebas.',
            '(PPN)' => 'Tidak ada. PPN masukannya sudah dikreditkan di pembukuan lama; yang dibawa hanya hutangnya, lawan Saldo Awal Konversi — bukan Persediaan.',
        ];
    }

    /** @return list<list<string>> */
    public static function contohPiutang(): array
    {
        return [
            ['INV-2026-0187', 'CV-MAJU', '2026-08-15', '2026-09-14', '12.500.000', 'Sisa setelah cicilan pertama'],
            ['INV-2026-0203', 'TB-JAYA', '2026-08-28', '', '4.750.000', ''],
        ];
    }

    /** @return list<list<string>> */
    public static function contohHutang(): array
    {
        return [
            ['SUP/0912/26', 'PT-YUHOLI', '2026-08-20', '2026-09-19', '38.000.000', ''],
        ];
    }
}
