<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Access\Role;

/**
 * The staff import format.
 *
 * Six columns, and the ones that matter are the ones that decide what a
 * person can see: PERAN, CABANG and GUDANG are access boundaries, which is
 * why this file is the Owner's alone to upload — the same hand that grants
 * every other permission.
 */
final class UserColumns
{
    /** @var list<string> */
    public const COLUMNS = ['NAMA', 'EMAIL', 'PERAN', 'CABANG', 'GUDANG', 'KATA_SANDI'];

    /** @return array<string, string> */
    public static function keterangan(): array
    {
        $peran = implode(', ', array_map(fn (Role $r) => $r->label(), Role::cases()));

        return [
            'NAMA' => 'Wajib. Nama lengkap seperti yang tampil di panel.',
            'EMAIL' => 'Wajib, unik — ini alamat masuknya. Email yang sudah terdaftar ditahan; ubah akun yang ada lewat layar Staf.',
            'PERAN' => "Wajib. Salah satu dari: {$peran}.",
            'CABANG' => 'Kode cabang (mis. SBY). Wajib untuk Sales, Inventori, dan Keuangan. Kosong untuk Marketing dan Pemilik — mereka melihat semua cabang. Gudang mengikuti gudangnya.',
            'GUDANG' => 'Kode gudang. Wajib untuk peran Gudang — satu gudang satu akun — dan kosong untuk peran lain.',
            'KATA_SANDI' => 'Opsional, minimal 12 karakter. Kosong: akun dibuat dengan kata sandi acak dan Pemilik mengaturnya dari layar Staf sebelum orangnya bisa masuk.',
        ];
    }

    /** @return list<list<string>> */
    public static function contoh(): array
    {
        return [
            ['Budi Santoso', 'budi@example.test', 'Sales', 'SBY', '', ''],
            ['Sari Dewi', 'sari@example.test', 'Marketing', '', '', ''],
            ['Agus Pratama', 'agus@example.test', 'Gudang', '', 'GD-SBY', ''],
        ];
    }
}
