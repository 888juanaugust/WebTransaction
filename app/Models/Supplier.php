<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who we buy from. Thin on purpose — see the migration.
 */
#[Fillable([
    'kode', 'nama', 'nama_kontak', 'telepon', 'email', 'alamat', 'npwp',
    'payment_terms_days', 'aktif', 'catatan',
])]
class Supplier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'payment_terms_days' => 'integer'];
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function paymentEntries(): HasMany
    {
        return $this->hasMany(SupplierPaymentEntry::class);
    }
}
