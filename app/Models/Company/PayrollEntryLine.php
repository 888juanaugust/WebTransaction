<?php

namespace App\Models\Company;

use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntryLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gross_amount' => 'integer', 'income_tax' => 'integer', 'net_amount' => 'integer', 'contribution_amount' => 'integer', 'taxable_gross' => 'integer'];
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /** Where the line's contribution or deduction is owed: BPJS, or a deduction's own account. */
    public function contributionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'contribution_account_id');
    }
}
