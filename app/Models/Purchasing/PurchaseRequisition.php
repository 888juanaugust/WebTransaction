<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Fulfilment\StatusDeriver;
use App\Models\Company\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Purchase Requisition: an internal request to buy (or send) items, pulled into purchase orders. Not posted. */
class PurchaseRequisition extends Model implements HasAuditReference
{
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'estimated_total' => 'integer', 'is_printed' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('sort');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refreshTotal(): void
    {
        $total = 0;
        foreach ($this->lines()->get() as $line) {
            $total += BigDecimal::of((string) $line->quantity)->multipliedBy((string) $line->estimated_price)->toScale(0, RoundingMode::HalfUp)->toInt();
        }
        $this->forceFill(['estimated_total' => $total])->saveQuietly();
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->forceFill(['status' => StatusDeriver::derive($this->lines()->get(), $this->status === StatusDeriver::CLOSED)])->saveQuietly();
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
