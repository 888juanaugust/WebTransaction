<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalogue\Golongan;
use App\Domain\Uom\Unit;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * KODE is the primary key. One row = one KODE.
 *
 * And once anything has said that KODE out loud, the row cannot go. A SKU is
 * referenced by name across nineteen tables — none of them by foreign key,
 * because the price list, the stock ledger and the order lines all carry the
 * code as a string — so the database will happily delete a product and leave
 * every one of those rows pointing at nothing.
 *
 * Measured: deleting a SKU with 200 units and Rp 2.000.000 in the stock
 * ledger left the movements exactly where they were, and the inventory
 * valuation, the unvalued-quantity report and the nightly integrity check all
 * carried on reporting zero. They iterate products, so a SKU with no product
 * row is not drift — it is nothing at all, and 200 units left the books with
 * nobody to notice.
 */
#[Fillable([
    'kode', 'merk', 'kategori', 'golongan', 'tipe_produk', 'mobil', 'part_number',
    'description', 'qty_per_ctn', 'satuan_dasar', 'aktif', 'catatan',
    'titik_pesan_ulang_manual', 'jangan_pesan_ulang',
])]
class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Every table that names a SKU, and what to call it when it says no.
     *
     * Kept as a list rather than derived at runtime, because a delete should
     * not query the information schema — and pinned by a test that *does*
     * derive it, so a table added later with an `sku` column fails the suite
     * instead of quietly becoming deletable-through.
     *
     * @var array<string, array{0: string, 1: string}> table => [column, label]
     */
    public const REFERENCING_TABLES = [
        'stock_movements' => ['sku', 'buku stok'],
        'stock_levels' => ['sku', 'saldo stok'],
        'stock_reservations' => ['sku', 'reservasi stok'],
        'stock_opname_lines' => ['sku', 'stok opname'],
        'stock_transfer_lines' => ['sku', 'transfer gudang'],
        'product_costs' => ['sku', 'harga pokok rata-rata'],
        'order_lines' => ['sku', 'baris order'],
        'quotation_lines' => ['sku', 'baris penawaran'],
        'credit_note_lines' => ['sku', 'baris nota kredit'],
        'cart_items' => ['sku', 'keranjang portal'],
        'purchase_order_lines' => ['sku', 'baris pesanan pembelian'],
        'purchase_return_lines' => ['sku', 'baris retur pembelian'],
        'goods_receipt_lines' => ['sku', 'penerimaan barang'],
        'supplier_bill_lines' => ['sku', 'baris tagihan pemasok'],
        'landed_cost_lines' => ['sku', 'biaya perolehan'],
        'price_list_items' => ['kode', 'daftar harga'],
        'price_list_import_rows' => ['kode', 'impor daftar harga'],
        'price_tier_items' => ['kode', 'tingkat harga'],
        'company_price_overrides' => ['kode', 'harga khusus pelanggan'],
    ];

    protected static function booted(): void
    {
        /*
         * On the model rather than only on the resource, so that a console
         * one-liner, a queued job and a future screen all meet the same
         * refusal. The screen still asks separately, through
         * ProductResource::canDelete, because a button that always errors is
         * worse than no button.
         */
        static::deleting(function (self $product) {
            $dipakai = $product->firstReference();

            if ($dipakai !== null) {
                throw new DomainException(
                    "SKU {$product->kode} masih dipakai di {$dipakai} dan tidak bisa dihapus. "
                    .'Nonaktifkan lewat kolom AKTIF: riwayatnya tetap terbaca, dan barang '
                    .'ini berhenti muncul di katalog dan di order baru.'
                );
            }
        });
    }

    /** Has anything happened to this SKU? Cheap enough to ask before a delete. */
    public function hasHistory(): bool
    {
        return $this->firstReference() !== null;
    }

    /**
     * The first place this SKU is spoken for, named so the refusal is useful.
     *
     * Unscoped by region deliberately: a SKU is the whole company's, and a
     * product still on an order in another region is still not deletable.
     */
    public function firstReference(): ?string
    {
        foreach (self::REFERENCING_TABLES as $table => [$column, $label]) {
            if (DB::table($table)->where($column, $this->kode)->exists()) {
                return $label;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'qty_per_ctn' => 'integer',
            'aktif' => 'boolean',
            'titik_pesan_ulang_manual' => 'integer',
            'jangan_pesan_ulang' => 'boolean',
        ];
    }

    /**
     * Impor / Titip impor / Lokal — or the honest word for not yet said.
     *
     * A plain string column rather than an enum cast: the reports read it
     * straight from SQL, the explorer prints it, and an enum object in either
     * place is one more thing to unwrap. The enum is the validator and the
     * dictionary, not the storage type.
     */
    public function golonganLabel(): string
    {
        return Golongan::tryFrom((string) $this->golongan)?->label() ?? Golongan::BELUM;
    }

    public function baseUnit(): Unit
    {
        return Unit::from($this->satuan_dasar);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'sku', 'kode');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sku', 'kode');
    }

    /** Moving-average cost. Null until this SKU has ever been received. */
    public function cost(): HasOne
    {
        return $this->hasOne(ProductCost::class, 'sku', 'kode');
    }
}
