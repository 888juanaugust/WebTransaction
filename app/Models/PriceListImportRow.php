<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_id', 'sheet_name', 'source_row_number', 'raw', 'kode', 'merk',
    'kategori', 'tipe_produk', 'mobil', 'part_number', 'description',
    'qty_per_ctn', 'satuan_dasar', 'harga', 'aktif', 'catatan',
    'status', 'issues', 'diff_bucket', 'harga_lama',
])]
class PriceListImportRow extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_NOTE = 'note';

    public const STATUS_BLOCKER = 'blocker';

    /** The five diff buckets shown in the preview. */
    public const BUCKET_NEW = 'sku_baru';

    public const BUCKET_CHANGED = 'harga_berubah';

    public const BUCKET_UNCHANGED = 'tidak_berubah';

    public const BUCKET_MISSING = 'tidak_ada_di_file';

    public const BUCKET_ERROR = 'error';

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'issues' => 'array',
            'qty_per_ctn' => 'integer',
            'harga' => 'integer',
            'harga_lama' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'import_id');
    }
}
