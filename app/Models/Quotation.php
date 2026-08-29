<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A penawaran: prices offered to one customer, good until a date.
 *
 * Expiry is deliberately not a status. `kedaluwarsa` is derived from
 * `valid_until` on every read — a stored "expired" flag needs a scheduled
 * job to stay true, and the flag would be wrong for exactly the hours
 * between midnight and the job. Derived arithmetic, never stored state,
 * same as the debt freeze.
 */
#[Fillable([
    'nomor', 'company_id', 'status', 'valid_until', 'subtotal_rupiah',
    'discount_rupiah', 'dpp_rupiah', 'ppn_rupiah', 'total_rupiah',
    'catatan', 'created_by',
])]
class Quotation extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_TERKIRIM = 'terkirim';

    public const STATUS_DITERIMA = 'diterima';

    public const STATUS_BATAL = 'batal';

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'subtotal_rupiah' => 'integer',
            'total_rupiah' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('urutan');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Past its date and never accepted — the offer no longer stands. */
    public function isExpired(): bool
    {
        return $this->status !== self::STATUS_DITERIMA
            && $this->valid_until->endOfDay()->isPast();
    }

    /** What the screen shows: the stored status, or the derived expiry over it. */
    public function statusTampil(): string
    {
        if ($this->isExpired() && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_TERKIRIM], true)) {
            return 'kedaluwarsa';
        }

        return $this->status;
    }
}
