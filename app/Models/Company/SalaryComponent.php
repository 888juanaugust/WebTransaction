<?php

namespace App\Models\Company;

use App\Domain\Audit\RecordsActivity;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A salary or allowance component and the expense account it is booked to. */
class SalaryComponent extends Model
{
    use RecordsActivity;

    /** @return array<string, string> kind → label: the tax office's income kinds for form 1721 */
    public static function feeTypes(): array
    {
        return [
            'salary' => __('Salary / pension / old-age benefit'),
            'tax_allowance' => __('Income tax allowance'),
            'tax_subsidy' => __('Income tax subsidy'),
            'other_allowance' => __('Other allowances'),
            'overtime' => __('Overtime and the like'),
            'accident_insurance' => __('Work accident insurance allowance'),
            'death_insurance' => __('Death insurance'),
            'honorarium' => __('Honoraria and similar rewards'),
            'health_premium_employer' => __('Health insurance premium paid by the employer'),
            'in_kind' => __('Benefits in kind'),
            'bonus' => __('Bonus, gratuity, production incentive and holiday allowance'),
            'pension_employer' => __('Pension contribution paid by the employer'),
            'deduction_no_tax' => __('Salary deduction (not reducing tax)'),
            'deduction_tax' => __('Salary reduction (reducing tax)'),
            'health_premium_employee' => __('Health insurance premium paid by the employee'),
            'pension_employee' => __('Pension contribution paid by the employee'),
        ];
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function isDeduction(): bool
    {
        return str_starts_with((string) $this->fee_type, 'deduction') || str_ends_with((string) $this->fee_type, '_employee');
    }
}
