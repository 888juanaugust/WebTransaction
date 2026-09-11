<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Komisi\JenisKomisi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's commission rate from one date onward.
 *
 * Append-only by convention: a change is a new row with a later
 * `berlaku_mulai`, never an UPDATE — so a commission report for a closed
 * month prints the same forever. `basis_poin` is basis points (150 = 1,50%),
 * an integer, because DECIMAL rates invite float arithmetic on money.
 */
#[Fillable(['user_id', 'jenis', 'cabang_id', 'basis_poin', 'berlaku_mulai', 'set_by'])]
class CommissionRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'basis_poin' => 'integer',
            'berlaku_mulai' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * The cabang a supervisor's rate applies to; null for every other kind.
     *
     * Deliberately `cabang_id`, not `region_id`: the rate is the Owner's,
     * not one region's books, so it must not carry the region scope.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'cabang_id');
    }

    public function jenis(): JenisKomisi
    {
        return JenisKomisi::from((string) ($this->jenis ?? JenisKomisi::Penjualan->value));
    }
}
