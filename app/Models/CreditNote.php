<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\CreditNoteType;
use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota kredit — what a customer no longer owes, and why.
 *
 * The money columns are not fillable. They are summed from the line snapshots
 * when the note is posted and are never editable afterwards, by anybody — the
 * same rule the invoice carries, and for a sharper reason: a credit note is
 * the one document in the system whose entire purpose is to reduce what
 * somebody owes.
 */
#[Fillable([
    'nomor', 'invoice_id', 'company_id', 'jenis', 'tanggal', 'alasan',
    'warehouse_id', 'nomor_nota_retur', 'created_by',
])]
class CreditNote extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected function casts(): array
    {
        return [
            'jenis' => CreditNoteType::class,
            'tanggal' => 'date',
            'subtotal_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'total_rupiah' => 'integer',
            'hpp_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isDraft(): bool
    {
        return ! $this->isPosted();
    }

    /**
     * Only posted notes reduce anything.
     *
     * A draft is somebody's intention. Counting it against a customer's
     * balance would let an unfinished document lower what they owe.
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
