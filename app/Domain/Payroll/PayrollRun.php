<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Shared\Enums\WorkStatus;
use App\Domain\Shared\Money;
use App\Models\Company\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payroll worked out for a payroll entry: each employee's pay setup with
 * BPJS and Art. 21 for the period, or the tax worked out again on the pay
 * already in the entry. The year so far is read from the other payroll
 * entries of the year. Gives the entry's lines; nothing is saved here.
 */
final class PayrollRun
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * Every active employee with a pay setup who works in the period.
     *
     * @return list<array<string, mixed>> the entry's lines
     */
    public function calculate(int $year, int $month, ?int $entryId = null): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $employees = Employee::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('join_date')->orWhere('join_date', '<=', $start->endOfMonth()->toDateString()))
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhere('exit_date', '>=', $start->toDateString()))
            ->whereHas('salaryComponents')
            ->with('salaryComponents.component')
            ->orderBy('name')->get();
        $rows = [];
        foreach ($employees as $employee) {
            $pay = $employee->salaryComponents->map(fn ($setup) => [
                'salary_component_id' => $setup->salary_component_id,
                'fee_type' => $setup->component?->fee_type,
                'amount' => (int) $setup->amount,
                'memo' => null,
            ])->all();
            array_push($rows, ...$this->linesFor($employee, $year, $month, $pay, $entryId, true));
        }

        return $rows;
    }

    /**
     * The entry's lines with each employee's tax worked out again on the pay they hold (BPJS lines kept as they are).
     *
     * @param  iterable<array<string, mixed>>  $rows  the grid's rows (amounts as typed)
     * @return list<array<string, mixed>>
     */
    public function recalculateTax(iterable $rows, int $year, int $month, ?int $entryId = null): array
    {
        $byEmployee = [];
        $out = [];
        foreach ($rows as $row) {
            if (empty($row['employee_id'])) {
                continue;
            }
            $kind = IncomeKinds::of($row['fee_type'] ?? null);
            $amount = IncomeKinds::amountOf($kind, Money::parse($row['gross_amount'] ?? 0), Money::parse($row['contribution_amount'] ?? 0));
            if ($amount === 0) {
                continue; // a tax-only line: the tax goes back on the pay
            }
            $byEmployee[(int) $row['employee_id']][] = ['salary_component_id' => filled($row['salary_component_id'] ?? null) ? (int) $row['salary_component_id'] : null,
                'fee_type' => $kind, 'amount' => $amount, 'memo' => $row['memo'] ?? null];
        }
        foreach (Employee::query()->whereKey(array_keys($byEmployee))->get()->keyBy('id') as $id => $employee) {
            array_push($out, ...$this->linesFor($employee, $year, $month, $byEmployee[$id], $entryId, false));
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function linesFor(Employee $employee, int $year, int $month, array $pay, ?int $entryId, bool $withBpjs): array
    {
        $result = $this->calculator->calculate($this->profile($employee, $year, $month), $year, $month, $pay, $this->yearToDate($employee->id, $year, $month, $entryId), $withBpjs);

        return array_map(fn (array $line) => ['employee_id' => $employee->id] + $line, $result['lines']);
    }

    /** @return array<string, mixed> the calculator's view of an employee in a period */
    public function profile(Employee $employee, int $year, int $month): array
    {
        $joinYear = $employee->start_year_payment ?: $employee->join_date?->year;
        $firstMonth = match (true) {
            (int) $employee->start_year_payment === $year && $employee->start_month_payment => (int) $employee->start_month_payment,
            $employee->join_date?->year === $year => $employee->join_date->month,
            default => 1,
        };
        $earlierEmployer = $joinYear === null || (int) $joinYear === $year;

        return [
            'ptkp' => $employee->tax_status?->value,
            'withholds' => (bool) $employee->withhold_income_tax,
            'permanent' => $employee->work_status === null || $employee->work_status === WorkStatus::Permanent,
            'bpjs_health' => (bool) $employee->bpjs_health,
            'bpjs_employment' => (bool) $employee->bpjs_employment,
            'jp_participant' => (bool) $employee->jp_participant,
            'jkk_rate' => (string) ($employee->jkk_rate ?? '0.24'),
            'first_month' => $firstMonth,
            'last_month' => $month === 12 || ($employee->exit_date !== null && $employee->exit_date->year === $year && $employee->exit_date->month === $month),
            'previous_income' => $earlierEmployer ? (int) $employee->previous_income : 0,
            'previous_tax' => $earlierEmployer ? (int) $employee->previous_tax : 0,
        ];
    }

    /**
     * The year so far from the other payroll entries: taxable gross, deductible contributions and tax of the
     * months before, and of other entries in this month.
     *
     * @return array{gross: int, deductible: int, tax: int, month_gross: int, month_deductible: int, month_tax: int}
     */
    public function yearToDate(int $employeeId, int $year, int $month, ?int $exceptEntryId = null): array
    {
        $ytd = ['gross' => 0, 'deductible' => 0, 'tax' => 0, 'month_gross' => 0, 'month_deductible' => 0, 'month_tax' => 0];
        $rows = DB::table('payroll_entry_lines as l')->join('payroll_entries as e', 'e.id', '=', 'l.payroll_entry_id')
            ->where('l.employee_id', $employeeId)->where('e.period_year', $year)->where('e.period_month', '<=', $month)
            ->when($exceptEntryId, fn ($q) => $q->where('e.id', '!=', $exceptEntryId))
            ->selectRaw("coalesce(l.fee_type, 'salary') as kind, e.period_month = ? as this_month, sum(l.gross_amount) as gross, sum(l.contribution_amount) as contribution, sum(l.income_tax) as tax", [$month])
            ->groupBy('kind', 'this_month')->get();
        foreach ($rows as $row) {
            $prefix = $row->this_month ? 'month_' : '';
            $ytd[$prefix.'gross'] += IncomeKinds::isTaxable($row->kind) ? (int) $row->gross : 0;
            $ytd[$prefix.'deductible'] += isset(IncomeKinds::DEDUCTIBLE[$row->kind]) ? (int) $row->contribution : 0;
            $ytd[$prefix.'tax'] += (int) $row->tax;
        }

        return $ytd;
    }
}
