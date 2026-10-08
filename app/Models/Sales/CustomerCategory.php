<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCategory extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
