<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wilayah — a region, and a complete set of books.
 *
 * Not scoped itself, and could not be: the scope is defined in terms of this
 * table, so a global scope here would be a query that filters itself.
 */
#[Fillable([
    'kode', 'nama', 'alamat', 'telepon',
    'npwp', 'nama_wajib_pajak', 'alamat_pajak',
    'aktif', 'catatan',
])]
class Region extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Who this region is, to the tax office.
     *
     * Falls back to the company-wide identity when the region has not been
     * given its own. Both arrangements are normal — one PT keeping regional
     * books apart for management and filing a single return, or genuinely
     * separate legal entities — and the fallback is what lets the first one
     * work without entering the same NPWP several times.
     *
     * @return array{npwp: ?string, nama: ?string, alamat: ?string}
     */
    public function identitasPajak(): array
    {
        return [
            'npwp' => $this->npwp ?: config('pajak.penjual.npwp'),
            'nama' => $this->nama_wajib_pajak ?: config('pajak.penjual.nama'),
            'alamat' => $this->alamat_pajak ?: config('pajak.penjual.alamat'),
        ];
    }

    /** True when this region files under its own NPWP rather than the group's. */
    public function punyaNpwpSendiri(): bool
    {
        return filled($this->npwp);
    }

    public function label(): string
    {
        return "{$this->kode} — {$this->nama}";
    }
}
