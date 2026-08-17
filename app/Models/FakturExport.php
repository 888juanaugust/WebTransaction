<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One filing: the fakturs reported for a tax period, and the file that was
 * handed over.
 */
#[Fillable([
    'nomor', 'masa_pajak', 'tahun_pajak', 'format', 'jumlah_faktur',
    'total_dpp_rupiah', 'total_ppn_rupiah', 'file_path', 'catatan', 'created_by',
])]
class FakturExport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'masa_pajak' => 'integer',
            'tahun_pajak' => 'integer',
            'jumlah_faktur' => 'integer',
            'total_dpp_rupiah' => 'integer',
            'total_ppn_rupiah' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FakturExportLine::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Agustus 2026" — the period reported, not the date it was filed. */
    public function periode(): string
    {
        static $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return ($bulan[$this->masa_pajak] ?? (string) $this->masa_pajak).' '.$this->tahun_pajak;
    }

    /** Fakturs in this filing still waiting for a number to come back. */
    public function menungguNsfp(): int
    {
        return $this->lines()->whereNull('nsfp')->count();
    }
}
