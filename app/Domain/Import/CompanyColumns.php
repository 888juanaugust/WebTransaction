<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * The customer import format.
 *
 * Same discipline as the price list's canonical columns: position is what
 * matters, the header is read for reassurance rather than for meaning, and
 * the template a person downloads is generated from this list so the example
 * they fill in cannot drift from what the parser expects.
 *
 * The order follows the customer form's own sections — identity, then where
 * to deliver, then tax, then the commercial terms — because the person
 * filling the file in is usually copying from the same paperwork they would
 * have typed into that screen.
 */
final class CompanyColumns
{
    /** @var list<string> */
    public const COLUMNS = [
        'KODE',
        'NAMA',
        'JENIS_USAHA',
        'NAMA_KONTAK',
        'TELEPON',
        'EMAIL',
        'KOTA',
        'ALAMAT_KIRIM',
        'NPWP',
        'NAMA_WAJIB_PAJAK',
        'ALAMAT_PAJAK',
        'TIER',
        'LIMIT_KREDIT',
        'TEMPO_HARI',
        'STATUS',
        'CATATAN',
    ];

    /**
     * What each column is for, shown beside the download rather than buried
     * in a manual. Wording is the same as the field's label on the customer
     * form, so somebody who has used one recognises the other.
     *
     * @return array<string, string>
     */
    public static function keterangan(): array
    {
        return [
            'KODE' => 'Wajib. Kode pelanggan, unik. Baris dengan kode yang sudah ada akan memperbarui data itu.',
            'NAMA' => 'Wajib. Nama usaha.',
            'JENIS_USAHA' => 'Wajib. bengkel, toko_sparepart, atau distributor.',
            'NAMA_KONTAK' => 'Nama orang yang dihubungi.',
            'TELEPON' => 'Nomor telepon.',
            'EMAIL' => 'Alamat email.',
            'KOTA' => 'Kota.',
            'ALAMAT_KIRIM' => 'Alamat pengiriman barang.',
            'NPWP' => 'Kosongkan bila belum ada. Tanpa NPWP, faktur pajaknya tidak bisa diekspor.',
            'NAMA_WAJIB_PAJAK' => 'Nama sesuai NPWP, bila berbeda dari nama usaha.',
            'ALAMAT_PAJAK' => 'Alamat sesuai NPWP.',
            'TIER' => 'Nama tier harga persis seperti di layar Tier harga, mis. Bengkel. Dikosongkan di contoh ini karena tier tiap perusahaan berbeda; kosong berarti harga dasar.',
            'LIMIT_KREDIT' => 'Angka rupiah tanpa titik, mis. 50000000. Hanya boleh diisi oleh Keuangan atau Pemilik.',
            'TEMPO_HARI' => 'Jumlah hari jatuh tempo, mis. 30. Kosong berarti 0 (bayar di muka).',
            'STATUS' => 'aktif, menunggu, atau ditangguhkan. Kosong berarti menunggu persetujuan.',
            'CATATAN' => 'Catatan bebas.',
        ];
    }

    /**
     * Two rows a person can read and imitate.
     *
     * Deliberately not one: a single example leaves it unclear which fields
     * may be blank. The second row is a cash customer with no NPWP, no tier
     * and no credit — the shape half the register actually takes.
     *
     * @return list<list<string>>
     */
    public static function contoh(): array
    {
        return [
            [
                'PLG-001',
                'Bengkel Jaya Motor',
                'bengkel',
                'Pak Budi',
                '081234567890',
                'jaya@contoh.com',
                'Surabaya',
                'Jl. Raya Darmo No. 12, Surabaya',
                '01.234.567.8-901.000',
                'CV Jaya Motor',
                'Jl. Raya Darmo No. 12, Surabaya',
                '',
                '50000000',
                '30',
                'aktif',
                'Pelanggan lama, pembayaran lancar',
            ],
            [
                'PLG-002',
                'Toko Sparepart Makmur',
                'toko_sparepart',
                'Ibu Sri',
                '081298765432',
                '',
                'Sidoarjo',
                'Jl. Pahlawan No. 5, Sidoarjo',
                '',
                '',
                '',
                '',
                '',
                '',
                'menunggu',
                'Belum ada NPWP, tunai dulu',
            ],
        ];
    }
}
