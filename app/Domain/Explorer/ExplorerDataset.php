<?php

declare(strict_types=1);

namespace App\Domain\Explorer;

use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\MovementReason;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the explorer can be pointed at.
 *
 * The reports elsewhere answer fixed questions well; this answers the ones
 * nobody anticipated — "every faktur for these four shops, oldest first",
 * "what moved out of Gudang Pusat last week". Each dataset is a query, a set
 * of columns and a set of filters, and nothing else: no aggregation, no
 * totals, no derived figures. A row here is a row in the register, which is
 * what makes it safe to export and argue from.
 *
 * Every dataset carries its own gate. The explorer would otherwise be the
 * back door around every role rule in the system — a warehouse account
 * reading invoice totals by picking a different item from a dropdown.
 */
enum ExplorerDataset: string
{
    case Order = 'order';
    case Faktur = 'faktur';
    case Pelanggan = 'pelanggan';
    case Barang = 'barang';
    case Stok = 'stok';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Order',
            self::Faktur => 'Faktur',
            self::Pelanggan => 'Pelanggan',
            self::Barang => 'Barang',
            self::Stok => 'Pergerakan stok',
        };
    }

    public function keterangan(): string
    {
        return match ($this) {
            self::Order => 'Setiap order beserta status dan gudang pengirimnya.',
            self::Faktur => 'Setiap faktur, jatuh temponya, dan sisa tagihannya.',
            self::Pelanggan => 'Daftar pelanggan beserta tim dan syarat dagangnya.',
            self::Barang => 'Katalog barang beserta stok saat ini.',
            self::Stok => 'Setiap pergerakan stok: masuk, keluar, koreksi, transfer.',
        };
    }

    /**
     * Who may point the explorer here.
     *
     * Deliberately per dataset rather than one gate on the page: the answer
     * differs. Inventori keep the catalogue and must read stock; they have no
     * business in a customer's credit terms.
     */
    public function canAccess(): bool
    {
        $role = auth()->user()?->role();

        if ($role === null) {
            return false;
        }

        return match ($this) {
            self::Order => $role->canCreateOrders() || $role->canSeeReports() || $role->canPickAndShip(),
            self::Faktur, self::Pelanggan => $role->canSeeCreditData(),
            self::Barang, self::Stok => $role->canCountStock() || $role->canSeeReports(),
        };
    }

    /** @return list<self> */
    public static function tersedia(): array
    {
        return array_values(array_filter(self::cases(), fn (self $d) => $d->canAccess()));
    }

    public function query(): Builder
    {
        return match ($this) {
            self::Order => Order::query()->with(['company', 'warehouse']),
            self::Faktur => Invoice::query()->with(['company']),
            self::Pelanggan => Company::query()->with(['priceTier', 'salesRep']),
            self::Barang => Product::query(),
            self::Stok => StockMovement::query()->with(['warehouse']),
        };
    }

    /** @return list<ExplorerColumn> */
    public function columns(): array
    {
        $seesPrices = fn () => auth()->user()?->role()->canSeePrices() ?? false;

        return match ($this) {
            self::Order => [
                ExplorerColumn::text('nomor', 'Nomor'),
                ExplorerColumn::date('created_at', 'Dibuat'),
                ExplorerColumn::text('company.nama', 'Pelanggan', sortBy: null),
                ExplorerColumn::text('status', 'Status',
                    value: fn ($r) => $r->status instanceof OrderStatus ? $r->status->label() : (string) $r->status),
                ExplorerColumn::text('warehouse.nama', 'Gudang'),
                ExplorerColumn::text('po_pelanggan', 'PO pelanggan'),
                ExplorerColumn::date('confirmed_at', 'Disetujui'),
                ExplorerColumn::date('shipped_at', 'Dikirim'),
            ],
            self::Faktur => [
                ExplorerColumn::text('nomor', 'Nomor'),
                ExplorerColumn::text('company.nama', 'Pelanggan'),
                ExplorerColumn::date('issued_on', 'Terbit'),
                ExplorerColumn::date('due_date', 'Jatuh tempo'),
                ExplorerColumn::money('dpp_rupiah', 'DPP', visible: $seesPrices),
                ExplorerColumn::money('ppn_rupiah', 'PPN', visible: $seesPrices),
                ExplorerColumn::money('total_rupiah', 'Total', visible: $seesPrices),
                ExplorerColumn::money('sisa', 'Sisa',
                    value: fn (Invoice $r) => $r->amountOutstanding(), visible: $seesPrices),
                ExplorerColumn::text('status', 'Status'),
                ExplorerColumn::text('nsfp', 'NSFP'),
            ],
            self::Pelanggan => [
                ExplorerColumn::text('kode', 'Kode'),
                ExplorerColumn::text('nama', 'Nama'),
                ExplorerColumn::text('jenis_usaha', 'Jenis'),
                ExplorerColumn::text('kota', 'Kota'),
                ExplorerColumn::text('salesRep.name', 'Sales'),
                ExplorerColumn::text('priceTier.nama', 'Tier'),
                ExplorerColumn::money('credit_limit_rupiah', 'Limit kredit'),
                ExplorerColumn::number('payment_terms_days', 'Tempo (hari)'),
                ExplorerColumn::text('status', 'Status'),
                ExplorerColumn::text('npwp', 'NPWP'),
                ExplorerColumn::text('telepon', 'Telepon'),
            ],
            self::Barang => [
                ExplorerColumn::text('kode', 'Kode'),
                ExplorerColumn::text('merk', 'Merk'),
                ExplorerColumn::text('kategori', 'Kategori'),
                ExplorerColumn::text('description', 'Deskripsi'),
                ExplorerColumn::text('part_number', 'Part number'),
                ExplorerColumn::text('mobil', 'Mobil'),
                ExplorerColumn::number('qty_per_ctn', 'Isi/karton'),
                ExplorerColumn::text('satuan_dasar', 'Satuan'),
                ExplorerColumn::number('stok', 'Stok',
                    value: fn (Product $r) => (int) $r->stockLevels()->sum('qty_on_hand'), sortBy: null),
                ExplorerColumn::text('aktif', 'Aktif', value: fn ($r) => $r->aktif ? 'Y' : 'N'),
            ],
            self::Stok => [
                ExplorerColumn::date('created_at', 'Waktu'),
                ExplorerColumn::text('sku', 'Kode'),
                ExplorerColumn::text('warehouse.nama', 'Gudang'),
                ExplorerColumn::number('qty_signed', 'Qty'),
                ExplorerColumn::text('reason', 'Alasan',
                    value: fn ($r) => $r->reason instanceof MovementReason ? $r->reason->value : (string) $r->reason),
                ExplorerColumn::text('reference_type', 'Dokumen',
                    value: fn ($r) => $r->reference_type === null ? null : class_basename($r->reference_type)),
                ExplorerColumn::text('reference_id', 'No. dokumen'),
            ],
        };
    }

    /**
     * The filters this dataset offers, as plain definitions the page turns
     * into Filament controls and the saved view stores by name.
     *
     * @return array<string, array{label: string, type: string, options?: array<string, string>, column?: string}>
     */
    public function filters(): array
    {
        return match ($this) {
            self::Order => [
                'status' => ['label' => 'Status', 'type' => 'select', 'column' => 'status',
                    'options' => collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])->all()],
                'periode' => ['label' => 'Dibuat', 'type' => 'daterange', 'column' => 'created_at'],
            ],
            self::Faktur => [
                'status' => ['label' => 'Status', 'type' => 'select', 'column' => 'status',
                    'options' => [
                        Invoice::STATUS_OPEN => 'Terbuka',
                        Invoice::STATUS_PAID => 'Lunas',
                        Invoice::STATUS_VOID => 'Batal',
                    ]],
                'periode' => ['label' => 'Terbit', 'type' => 'daterange', 'column' => 'issued_on'],
                'jatuh_tempo' => ['label' => 'Hanya yang lewat jatuh tempo', 'type' => 'toggle'],
            ],
            self::Pelanggan => [
                'status' => ['label' => 'Status', 'type' => 'select', 'column' => 'status',
                    'options' => [
                        Company::STATUS_ACTIVE => 'Aktif',
                        Company::STATUS_PENDING => 'Menunggu persetujuan',
                        Company::STATUS_SUSPENDED => 'Ditangguhkan',
                    ]],
                'jenis_usaha' => ['label' => 'Jenis usaha', 'type' => 'select', 'column' => 'jenis_usaha',
                    'options' => ['bengkel' => 'Bengkel', 'toko_sparepart' => 'Toko sparepart',
                        'distributor' => 'Distributor']],
            ],
            self::Barang => [
                'merk' => ['label' => 'Merk', 'type' => 'select', 'column' => 'merk',
                    'options' => ['YUHOLI' => 'YUHOLI', 'OSBORN' => 'OSBORN', 'ASTRO' => 'ASTRO',
                        'STAVO' => 'STAVO', 'STAVIX' => 'STAVIX', 'SERVO' => 'SERVO', 'BDAX' => 'BDAX']],
                'kategori' => ['label' => 'Kategori', 'type' => 'select', 'column' => 'kategori',
                    'options' => ['HYDRAULIC PART' => 'HYDRAULIC PART', 'SUSPENSION PART' => 'SUSPENSION PART',
                        'ELECTRIC PART' => 'ELECTRIC PART', 'BEARING PART' => 'BEARING PART']],
                'aktif' => ['label' => 'Hanya yang aktif', 'type' => 'toggle'],
            ],
            self::Stok => [
                'reason' => ['label' => 'Alasan', 'type' => 'select', 'column' => 'reason',
                    'options' => collect(MovementReason::cases())
                        ->mapWithKeys(fn (MovementReason $r) => [$r->value => $r->value])->all()],
                'periode' => ['label' => 'Waktu', 'type' => 'daterange', 'column' => 'created_at'],
            ],
        };
    }

    /** @return list<string> the columns a free-text search looks in */
    public function searchable(): array
    {
        return match ($this) {
            self::Order => ['nomor', 'po_pelanggan'],
            self::Faktur => ['nomor', 'nsfp'],
            self::Pelanggan => ['kode', 'nama', 'kota', 'telepon', 'npwp'],
            self::Barang => ['kode', 'description', 'part_number', 'mobil'],
            self::Stok => ['sku', 'reference_id'],
        };
    }
}
