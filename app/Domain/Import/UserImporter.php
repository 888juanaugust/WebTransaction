<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Domain\Audit\AuditLogger;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff accounts from a spreadsheet, with a look before the leap.
 *
 * Create-only. A row whose email already exists is held, not updated: what
 * an existing account may see is changed through `StaffRegistrar` from the
 * Staf screen, where each change is its own audited act with its own
 * reason. A CSV that could quietly re-role forty people is not a feature.
 *
 * Every account is created through `StaffRegistrar::create`, so the same
 * rules hold as for the form — one active Gudang account per warehouse, a
 * packer's region is their warehouse's, Marketing and the Owner carry no
 * region — and the same `staff_created` audit row is written per person,
 * plus one summary row for the file.
 *
 * Passwords: given in the file (twelve characters or more) or generated.
 * A generated one is never shown anywhere; the row says the Owner must set
 * one from the Staf screen before that person can sign in, which is the
 * safe default for a file that gets emailed around.
 */
class UserImporter
{
    use ReadsCsv;

    public function __construct(
        private readonly StaffRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<BarisImpor> */
    public function preview(string $contents, User $actor): array
    {
        $this->assertMayImport($actor);

        $lines = $this->lines($contents);

        if ($lines === []) {
            throw new DomainException('Berkasnya kosong.');
        }

        $header = $this->header(array_shift($lines), ['NAMA', 'EMAIL', 'PERAN']);

        $existing = User::query()->pluck('email')->map(fn ($e) => strtolower((string) $e))->flip()->all();
        $regions = Region::query()->where('aktif', true)->get()->keyBy(fn (Region $r) => strtoupper($r->kode));
        $warehouses = Warehouse::query()->withoutGlobalScope('region')->where('aktif', true)->get()
            ->keyBy(fn (Warehouse $w) => strtoupper($w->kode));
        $roles = [];

        foreach (Role::cases() as $role) {
            $roles[strtoupper($role->label())] = $role;
            $roles[strtoupper($role->value)] = $role;
        }

        $rows = [];
        $seen = [];
        $nomor = 1;

        foreach ($lines as $line) {
            $nomor++;

            if ($this->blank($line)) {
                continue;
            }

            $cells = $this->cells($line, $header);
            $alasan = [];
            $catatan = [];

            $nama = $this->teks($cells, 'NAMA');
            $email = strtolower($this->teks($cells, 'EMAIL'));

            if ($nama === '') {
                $alasan[] = 'NAMA kosong';
            }

            if ($email === '') {
                $alasan[] = 'EMAIL kosong';
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alasan[] = "EMAIL '{$email}' bukan alamat email";
            } elseif (isset($existing[$email])) {
                $alasan[] = "EMAIL {$email} sudah terdaftar — ubah lewat layar Staf";
            } elseif (isset($seen[$email])) {
                $alasan[] = "EMAIL {$email} muncul dua kali di berkas ini";
            }

            $peranRaw = $this->teks($cells, 'PERAN');
            $role = $roles[strtoupper($peranRaw)] ?? null;

            if ($role === null) {
                $alasan[] = $peranRaw === ''
                    ? 'PERAN kosong'
                    : "PERAN '{$peranRaw}' tidak dikenal (".implode(', ', array_map(fn (Role $r) => $r->label(), Role::cases())).')';
            }

            $cabangKode = strtoupper($this->teks($cells, 'CABANG'));
            $gudangKode = strtoupper($this->teks($cells, 'GUDANG'));
            $region = $cabangKode === '' ? null : ($regions[$cabangKode] ?? null);
            $warehouse = $gudangKode === '' ? null : ($warehouses[$gudangKode] ?? null);

            if ($cabangKode !== '' && $region === null) {
                $alasan[] = "CABANG '{$cabangKode}' tidak dikenal";
            }

            if ($gudangKode !== '' && $warehouse === null) {
                $alasan[] = "GUDANG '{$gudangKode}' tidak dikenal";
            }

            if ($role !== null) {
                if ($role === Role::Storage) {
                    if ($warehouse === null) {
                        $alasan[] = 'Peran Gudang wajib punya GUDANG';
                    }
                    if ($cabangKode !== '') {
                        $catatan[] = 'CABANG diabaikan: akun Gudang mengikuti cabang gudangnya';
                    }
                } elseif (in_array($role, [Role::Owner, Role::Marketing], true)) {
                    if ($cabangKode !== '') {
                        $catatan[] = "CABANG diabaikan: {$role->label()} melihat semua cabang";
                    }
                    if ($gudangKode !== '') {
                        $catatan[] = 'GUDANG diabaikan untuk peran ini';
                    }
                } else {
                    if ($region === null && $cabangKode === '') {
                        $alasan[] = "Peran {$role->label()} wajib punya CABANG";
                    }
                    if ($gudangKode !== '') {
                        $catatan[] = 'GUDANG diabaikan untuk peran ini';
                    }
                }
            }

            $sandi = $this->teks($cells, 'KATA_SANDI');
            $sandiDibuat = false;

            if ($sandi === '') {
                $sandi = Str::password(20);
                $sandiDibuat = true;
                $catatan[] = 'Kata sandi acak — atur dari layar Staf sebelum orangnya bisa masuk';
            } elseif (mb_strlen($sandi) < 12) {
                $alasan[] = 'KATA_SANDI kurang dari 12 karakter';
            }

            if ($email !== '') {
                $seen[$email] = true;
            }

            $rows[] = BarisImpor::dari($nomor, $email, $nama, [
                'nama' => $nama,
                'email' => $email,
                'role' => $role,
                'region_id' => $region?->id,
                'warehouse_id' => $warehouse?->id,
                'password' => $sandi,
                'sandi_dibuat' => $sandiDibuat,
            ], $alasan, $catatan);
        }

        return $rows;
    }

    /** @return array{baru: int, tertahan: int} */
    public function import(string $contents, User $actor, ?string $sumber = null): array
    {
        $rows = $this->preview($contents, $actor);

        $baru = 0;
        $tertahan = 0;

        DB::transaction(function () use ($rows, $actor, $sumber, &$baru, &$tertahan) {
            foreach ($rows as $row) {
                if ($row->tertahan()) {
                    $tertahan++;

                    continue;
                }

                $this->registrar->create(
                    nama: $row->nilai['nama'],
                    email: $row->nilai['email'],
                    role: $row->nilai['role'],
                    password: $row->nilai['password'],
                    actor: $actor,
                    regionId: $row->nilai['region_id'],
                    warehouseId: $row->nilai['warehouse_id'],
                );
                $baru++;
            }

            $this->audit->log(
                action: 'users_imported',
                newValue: ['baru' => $baru, 'tertahan' => $tertahan, 'berkas' => $sumber],
                actor: $actor,
            );
        });

        return ['baru' => $baru, 'tertahan' => $tertahan];
    }

    private function assertMayImport(User $actor): void
    {
        if (! $actor->role()->canManageStaff()) {
            throw new DomainException('Hanya Pemilik yang bisa mengimpor akun staf.');
        }
    }
}
