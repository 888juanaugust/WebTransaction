<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Format;
use App\Models\Company\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Salesman Commission: a commission rule, for every salesperson or chosen ones, with its requirement and gain. */
class SalesmanCommission extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'levels' => 'array', 'requirement_from' => 'integer', 'requirement_to' => 'integer', 'requirement_qty' => 'decimal:4', 'gain_value' => 'decimal:4', 'gain_amount' => 'integer', 'is_active' => 'boolean'];
    }

    public function salesmen(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'salesman_commission_employees', 'salesman_commission_id', 'employee_id');
    }

    public function periodLabel(): string
    {
        return $this->active_period === 'forever' ? 'Always' : Format::date($this->from_date).' – '.Format::date($this->to_date);
    }
}
