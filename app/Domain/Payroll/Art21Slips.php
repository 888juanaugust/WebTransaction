<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Shared\Enums\WorkStatus;
use App\Models\Company\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Art. 21 figures the tax office receives, read from the payroll
 * entries: the month's return (each employee's taxable gross, TER rate and
 * tax withheld) and the year's A1 slip per employee (its rows, the annual
 * calculation and what was withheld).
 */
final class Art21Slips
{
    public function __construct(private readonly PayrollRun $run, private readonly PayrollCalculator $calculator) {}

    /**
     * One row per employee paid in the month.
     *
     * @return list<array{employee: Employee, gross: int, tax: int, method: string, category: ?string, rate: ?string}>
     */
    public function month(int $year, int $month): array
    {
        $lines = $this->lines()->where('e.period_year', $year)->where('e.period_month', $month)->get()->groupBy('employee_id');
        $employees = Employee::query()->whereKey($lines->keys())->orderBy('name')->get();
        $rows = [];
        foreach ($employees as $employee) {
            $mine = $lines[$employee->id];
            $gross = (int) $mine->filter(fn ($l) => IncomeKinds::isTaxable($l->kind))->sum('gross');
            $profile = $this->run->profile($employee, $year, $month);
            $stored = $mine->first(fn ($l) => $l->tax_method !== null);
            $method = $stored->tax_method ?? (! $profile['withholds'] || $profile['ptkp'] === null ? 'none' : ($profile['last_month'] ? 'annual' : 'ter'));
            $category = $profile['ptkp'] !== null ? Pph21::category($profile['ptkp']) : null;
            $rows[] = [
                'employee' => $employee,
                'gross' => $gross,
                'tax' => (int) $mine->sum('tax'),
                'method' => $method,
                'category' => $category,
                'rate' => $method === 'ter' ? Pph21::rateText($stored->ter_rate ?? Pph21::terRate((string) $category, $gross)) : null,
            ];
        }

        return $rows;
    }

    /**
     * Each employee's A1 slip for the year: one per employee paid in it.
     *
     * @return list<array<string, mixed>>
     */
    public function year(int $year): array
    {
        $lines = $this->lines()->where('e.period_year', $year)->get()->groupBy('employee_id');
        $slips = [];
        foreach (Employee::query()->whereKey($lines->keys())->orderBy('name')->get() as $employee) {
            $slips[] = $this->slip($employee, $year, $lines[$employee->id]);
        }

        return $slips;
    }

    /** @return array<string, mixed>|null */
    public function slipFor(Employee $employee, int $year): ?array
    {
        $lines = $this->lines()->where('e.period_year', $year)->where('l.employee_id', $employee->id)->get();

        return $lines->isEmpty() ? null : $this->slip($employee, $year, $lines);
    }

    /** @return array<string, mixed> */
    private function slip(Employee $employee, int $year, iterable $lines): array
    {
        $lines = collect($lines);
        $rows = array_fill_keys(array_unique(array_values(IncomeKinds::A1_ROWS)), 0);
        $deductible = ['pension' => 0, 'zakat' => 0];
        foreach ($lines as $line) {
            if (isset(IncomeKinds::A1_ROWS[$line->kind])) {
                $rows[IncomeKinds::A1_ROWS[$line->kind]] += (int) $line->gross;
            }
            if (isset(IncomeKinds::DEDUCTIBLE[$line->kind])) {
                $deductible[IncomeKinds::DEDUCTIBLE[$line->kind]] += (int) $line->contribution;
            }
        }
        $monthEnd = (int) $lines->max('period_month');
        if ($employee->exit_date !== null && $employee->exit_date->year === $year) {
            $monthEnd = max($monthEnd, $employee->exit_date->month);
        }
        $profile = $this->run->profile($employee, $year, $monthEnd);
        $annual = $this->calculator->annual($profile, $monthEnd, array_sum($rows), array_sum($deductible));
        $withheld = (int) $lines->sum('tax');
        $due = $annual['tax'] - (int) $profile['previous_tax'];

        return [
            'employee' => $employee,
            'year' => $year,
            'month_start' => max(1, (int) $profile['first_month']),
            'month_end' => $monthEnd,
            'complete' => $monthEnd === 12 || $profile['last_month'],
            'rows' => $rows,
            'pension' => $deductible['pension'],
            'zakat' => $deductible['zakat'],
            'annual' => $annual,
            'previous_tax' => (int) $profile['previous_tax'],
            'tax_due' => $due,
            'withheld' => $withheld,
            'difference' => $due - $withheld,
            'ptkp_status' => $profile['ptkp'],
            'permanent' => $employee->work_status === null || $employee->work_status === WorkStatus::Permanent,
            'date' => CarbonImmutable::create($year, $monthEnd, 1)->endOfMonth()->toDateString(),
        ];
    }

    private function lines(): Builder
    {
        return DB::table('payroll_entry_lines as l')->join('payroll_entries as e', 'e.id', '=', 'l.payroll_entry_id')
            ->selectRaw("l.employee_id, coalesce(l.fee_type, 'salary') as kind, e.period_month, l.gross_amount as gross, l.contribution_amount as contribution, l.income_tax as tax, l.tax_method, l.ter_rate");
    }
}
