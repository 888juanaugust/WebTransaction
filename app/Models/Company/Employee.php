<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Enums\PtkpStatus;
use App\Domain\Shared\Enums\WorkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'exit_date' => 'date',
            'bpjs_health' => 'boolean',
            'bpjs_employment' => 'boolean',
            'jp_participant' => 'boolean',
            'is_salesman' => 'boolean',
            'withhold_income_tax' => 'boolean',
            'work_status' => WorkStatus::class,
            'tax_status' => PtkpStatus::class,
            'previous_income' => 'integer',
            'previous_tax' => 'integer',
            'is_active' => 'boolean',
            // Personal numbers, encrypted with the application key (UU PDP 27/2022).
            'nik_no' => 'encrypted',
            'npwp_no' => 'encrypted',
            'bank_account' => 'encrypted',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** The pay setup: each salary component the employee is paid every month, with its amount. */
    public function salaryComponents(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponent::class)->orderBy('sort');
    }

    public function scopeSalesmen(Builder $query): Builder
    {
        return $query->where('is_salesman', true)->where('is_active', true);
    }

    public function auditReference(): string
    {
        return "{$this->number} {$this->name}";
    }
}
