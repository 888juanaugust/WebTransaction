<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Documents\Accounts;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Money;
use App\Models\Company\SalaryComponent;

/**
 * What each income kind of a salary component (its fee_type) means for pay,
 * posting and Art. 21. Taxable kinds make up the gross the tax is worked out
 * on and fall in a row of the A1 slip; deductions come off the employee's
 * pay; employer contributions are paid to a fund, not to the employee.
 */
final class IncomeKinds
{
    /** Taxable kind → its A1 row. */
    public const A1_ROWS = [
        'salary' => 'salary',
        'tax_allowance' => 'tax_allowance',
        'tax_subsidy' => 'tax_allowance',
        'other_allowance' => 'other_allowance',
        'overtime' => 'other_allowance',
        'honorarium' => 'honorarium',
        'accident_insurance' => 'insurance',
        'death_insurance' => 'insurance',
        'health_premium_employer' => 'insurance',
        'in_kind' => 'in_kind',
        'bonus' => 'bonus',
    ];

    /** Taken off the employee's pay. */
    public const DEDUCTIONS = ['deduction_no_tax', 'deduction_tax', 'health_premium_employee', 'pension_employee'];

    /** Deductions that reduce net income on the annual calculation: pension and old-age contributions (A1 row 10), zakat through the employer. */
    public const DEDUCTIBLE = ['pension_employee' => 'pension', 'deduction_tax' => 'zakat'];

    /** Paid by the employer to a fund (BPJS, an insurer), not to the employee. */
    public const EMPLOYER_CONTRIBUTIONS = ['health_premium_employer', 'accident_insurance', 'death_insurance', 'pension_employer'];

    /** A line without a kind (entered before kinds were kept) counts as salary. */
    public static function of(?string $feeType): string
    {
        return $feeType === null || $feeType === '' ? 'salary' : $feeType;
    }

    public static function isTaxable(?string $feeType): bool
    {
        return isset(self::A1_ROWS[self::of($feeType)]);
    }

    public static function isDeduction(?string $feeType): bool
    {
        return in_array(self::of($feeType), self::DEDUCTIONS, true);
    }

    public static function isEmployerContribution(?string $feeType): bool
    {
        return in_array(self::of($feeType), self::EMPLOYER_CONTRIBUTIONS, true);
    }

    /**
     * A line's amounts from what was typed or worked out: an earning is gross pay; an employer contribution is
     * gross (the expense) owed to the fund; a deduction is owed to its account out of the employee's pay. Net is
     * always gross less tax less contribution, so every line balances.
     *
     * @return array{gross_amount: int, contribution_amount: int, net_amount: int}
     */
    public static function amounts(?string $feeType, int $amount, int $tax = 0): array
    {
        [$gross, $contribution] = match (true) {
            self::isDeduction($feeType) => [0, $amount],
            self::isEmployerContribution($feeType) => [$amount, $amount],
            default => [$amount, 0],
        };

        return ['gross_amount' => $gross, 'contribution_amount' => $contribution, 'net_amount' => $gross - $tax - $contribution];
    }

    /** What a saved line amounts to in its kind (the inverse of amounts()). */
    public static function amountOf(?string $feeType, int $gross, int $contribution): int
    {
        return self::isDeduction($feeType) ? $contribution : $gross;
    }

    /**
     * A payroll line as it is saved: its kind (the component's, else as given), net worked out again so the line
     * balances, and where its contribution or deduction is owed (a deduction component's own account, else BPJS).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $component = filled($data['salary_component_id'] ?? null) ? SalaryComponent::query()->find($data['salary_component_id']) : null;
        $data['fee_type'] = self::of($component?->fee_type ?? ($data['fee_type'] ?? null));
        foreach (['gross_amount', 'income_tax', 'contribution_amount'] as $column) {
            $data[$column] = Money::parse($data[$column] ?? 0);
        }
        $data['net_amount'] = $data['gross_amount'] - $data['income_tax'] - $data['contribution_amount'];
        $data['contribution_account_id'] = $data['contribution_amount'] === 0 ? null
            : ($component !== null && self::isDeduction($data['fee_type']) ? $component->expense_account_id : Accounts::payroll(PreferensiKey::BpjsPayableAccount));
        foreach (['salary_component_id', 'tax_method', 'ter_category', 'ter_rate', 'taxable_gross', 'department_id', 'project_id'] as $column) {
            if (($data[$column] ?? null) === '') {
                $data[$column] = null;
            }
        }

        return $data;
    }
}
