<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Giro\GiroDirection;
use App\Domain\Giro\GiroStatus;
use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bilyet giro, in either direction.
 *
 * `status` is not fillable and neither is the payment link. Only GiroRegister
 * moves them, because moving them is what writes the journal — the same rule
 * every other money document here follows.
 */
#[Fillable([
    'nomor', 'arah', 'company_id', 'supplier_id', 'invoice_id', 'supplier_bill_id',
    'bank_penerbit', 'nomor_warkat', 'nilai_rupiah', 'tanggal_terima',
    'tanggal_jatuh_tempo', 'catatan', 'created_by',
])]
class Giro extends Model
{
    use HasFactory;
    use HasRegion;

    protected $attributes = ['status' => GiroStatus::Beredar->value];

    protected function casts(): array
    {
        return [
            'arah' => GiroDirection::class,
            'status' => GiroStatus::class,
            'nilai_rupiah' => 'integer',
            'tanggal_terima' => 'date',
            'tanggal_jatuh_tempo' => 'date',
            'tanggal_setor' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }

    public function supplierPaymentEntry(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Whoever the paper is between us and, whichever way it points. */
    public function counterpartyName(): string
    {
        return $this->arah === GiroDirection::Masuk
            ? ($this->company?->nama ?? '—')
            : ($this->supplier?->nama ?? '—');
    }

    /** The document it settles, if it settles exactly one. */
    public function documentNumber(): ?string
    {
        return $this->arah === GiroDirection::Masuk
            ? $this->invoice?->nomor
            : $this->supplierBill?->nomor;
    }

    /**
     * Outstanding, and the date on it has passed.
     *
     * For a giro masuk that means either nobody has banked it or the bank has
     * not come back — both are work, and which one it is shows in
     * `tanggal_setor`. For a giro keluar it means the supplier has not
     * presented it yet, and the cash we set aside is still sitting there.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        return $this->isOpen()
            && $this->tanggal_jatuh_tempo->lessThan(($asOf ?? Carbon::now())->copy()->startOfDay());
    }

    /** Outstanding and bankable today. */
    public function isDue(?Carbon $asOf = null): bool
    {
        return $this->isOpen()
            && $this->tanggal_jatuh_tempo->lessThanOrEqualTo(($asOf ?? Carbon::now())->copy()->endOfDay());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', GiroStatus::Beredar->value);
    }

    public function scopeMasuk(Builder $query): Builder
    {
        return $query->where('arah', GiroDirection::Masuk->value);
    }

    public function scopeKeluar(Builder $query): Builder
    {
        return $query->where('arah', GiroDirection::Keluar->value);
    }
}
