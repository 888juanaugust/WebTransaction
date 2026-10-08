<?php

namespace App\Models\Inventory;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock Opname Result: the counted quantities against the system's; approval posts the difference. */
class StockOpnameResult extends Model implements HasAuditReference
{
    use RecordsActivity;
    use RequiresApproval;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'approved_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StockOpnameOrder::class, 'stock_opname_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOpnameResultLine::class)->orderBy('sort');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'inventory_adjustment_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
