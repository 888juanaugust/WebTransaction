<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
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
    use HasRegion;

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

    /** Buyer logins for this company's portal access. */
    public function customerUsers(): HasMany
    {
        return $this->hasMany(CustomerUser::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentEntries(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }

    /**
     * The team in charge: one sales, one marketing.
     *
     * Deliberately not in Fillable — assignment goes through TeamAssigner,
     * which checks the seat's role, the region, and writes the audit entry.
     * "Who approved this customer's credit" traces back through "who was
     * their marketing at the time", so the columns must not be settable by a
     * stray mass assignment.
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function marketingRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_user_id');
    }

    /** The customers a salesperson answers for. */
    public function scopeManagedBySales($query, User $sales)
    {
        return $query->where('sales_user_id', $sales->getKey());
    }

    /** The customers whose credit a marketing answers for. */
    public function scopeManagedByMarketing($query, User $marketing)
    {
        return $query->where('marketing_user_id', $marketing->getKey());
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
