<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kode', 'nama', 'jenis_usaha', 'npwp', 'nama_wajib_pajak', 'alamat_pajak',
    'alamat_kirim', 'kota', 'telepon', 'email', 'nama_kontak', 'price_tier_id',
    'credit_limit_rupiah', 'payment_terms_days', 'status', 'catatan',
])]
class Company extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected function casts(): array
    {
        return [
            'credit_limit_rupiah' => 'integer',
            'payment_terms_days' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function priceOverrides(): HasMany
    {
        return $this->hasMany(CompanyPriceOverride::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentEntries(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }

    public function virtualAccounts(): HasMany
    {
        return $this->hasMany(VirtualAccount::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
