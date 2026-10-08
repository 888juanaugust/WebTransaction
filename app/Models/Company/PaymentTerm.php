<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:4',
            'discount_days' => 'integer',
            'due_days' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function dueDate(CarbonInterface $transDate): CarbonInterface
    {
        return $transDate->copy()->addDays($this->due_days);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->first();
    }
}
