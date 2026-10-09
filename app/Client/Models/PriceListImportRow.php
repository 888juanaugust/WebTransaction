<?php

declare(strict_types=1);

namespace App\Client\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of an uploaded price list as the parser read it, with its issues and its place in the diff. */
class PriceListImportRow extends Model
{
    public const OK = 'ok';

    public const NOTE = 'note';

    public const BLOCKER = 'blocker';

    public const NEW = 'sku_baru';

    public const CHANGED = 'harga_berubah';

    public const UNCHANGED = 'tidak_berubah';

    public const MISSING = 'tidak_ada_di_file';

    public const ERROR = 'error';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw' => 'array', 'issues' => 'array', 'aktif' => 'boolean', 'harga' => 'integer', 'harga_lama' => 'integer', 'qty_per_ctn' => 'integer'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'import_id');
    }

    public function isBlocker(): bool
    {
        return $this->status === self::BLOCKER;
    }
}
