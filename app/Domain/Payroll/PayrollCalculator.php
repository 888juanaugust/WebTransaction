<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use Carbon\CarbonImmutable;

/**
 * One employee's pay for one entry, worked out: the pay lines (with BPJS
 * when asked), and the Art. 21 tax. Every month but the last withholds the
 * TER rate of the employee's category on the month's taxable gross (what
 * other entries of the month already paid counts too); the last month of
 * the year, or of the employment, works out the year's tax on the Art. 17
 * brackets and withholds what the earlier months did not. Pure: the year
 * so far comes in from PayrollYearToDate.
 *
 * Profile: ptkp ("K/1"), withholds (the employee has Art. 21 withheld),
 * permanent, bpjs_health, bpjs_employment, jp_participant, jkk_rate,
 * first_month (the first month of the year the employer pays),
 * last_month (this is December or the month the employee leaves),
 * previous_income (net income from an earlier employer this year) and
 * previous_tax (tax that employer withheld).
 */
final class PayrollCalculator
{
    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array{salary_component_id: ?int, fee_type: ?string, amount: int, memo?: ?string}>  $pay  amounts by kind, all positive
     * @param  array{gross: int, deductible: int, tax: int, month_gross: int, month_deductible: int, month_tax: int}  $ytd  this year's earlier months, and other entries of this month
     * @return array{lines: list<array<string, mixed>>, tax: array<string, mixed>}
     */
    public function calculate(array $profile, int $year, int $month, array $pay, array $ytd, bool $withBpjs): array
    {
        $payDate = CarbonImmutable::create($year, $month, 1)->endOfMonth();
        if ($withBpjs) {
            $wage = array_sum(array_map(fn (array $p) => in_array(IncomeKinds::of($p['fee_type']), (array) config('payroll.bpjs.wage_fee_types'), true) ? (int) $p['amount'] : 0, $pay));
            foreach (Bpjs::contributions($wage, $profile, $payDate) as $contribution) {
                $pay[] = ['salary_component_id' => null] + $contribution;
            }
        }

        $gross = 0;
        $deductible = 0;
        foreach ($pay as $p) {
            $kind = IncomeKinds::of($p['fee_type']);
            $gross += IncomeKinds::isTaxable($kind) ? (int) $p['amount'] : 0;
            $deductible += isset(IncomeKinds::DEDUCTIBLE[$kind]) ? (int) $p['amount'] : 0;
        }
        $tax = $this->tax($profile, $month, $gross, $deductible, $ytd);

        $lines = [];
        $taxLine = null;
        foreach ($pay as $i => $p) {
            $kind = IncomeKinds::of($p['fee_type']);
            $lines[$i] = [
                'salary_component_id' => $p['salary_component_id'] ?? null,
                'fee_type' => $kind,
                'memo' => $p['memo'] ?? null,
                'income_tax' => 0,
                'tax_method' => null, 'ter_category' => null, 'ter_rate' => null, 'taxable_gross' => null,
            ] + IncomeKinds::amounts($kind, (int) $p['amount']);
            if ($taxLine === null && $kind === 'salary') {
                $taxLine = $i;
            }
        }
        // The tax rides on the salary line (else the first earning, else a line of its own).
        $taxLine ??= array_key_first(array_filter($lines, fn (array $l) => ! IncomeKinds::isDeduction($l['fee_type']) && ! IncomeKinds::isEmployerContribution($l['fee_type'])));
        if ($tax['method'] !== 'none') {
            if ($taxLine === null) {
                $lines[] = ['salary_component_id' => null, 'fee_type' => null, 'memo' => __('Income tax Art. 21'), 'income_tax' => 0] + IncomeKinds::amounts('salary', 0);
                $taxLine = array_key_last($lines);
            }
            $lines[$taxLine]['income_tax'] = $tax['tax'];
            $lines[$taxLine]['net_amount'] -= $tax['tax'];
            $lines[$taxLine]['tax_method'] = $tax['method'];
            $lines[$taxLine]['ter_category'] = $tax['category'];
            $lines[$taxLine]['ter_rate'] = $tax['rate'];
            $lines[$taxLine]['taxable_gross'] = $tax['taxable_gross'];
        }

        return ['lines' => array_values($lines), 'tax' => $tax];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, int>  $ytd
     * @return array{method: string, category: ?string, rate: ?string, taxable_gross: int, tax: int, annual: ?array<string, int>}
     */
    public function tax(array $profile, int $month, int $gross, int $deductible, array $ytd): array
    {
        $monthGross = $ytd['month_gross'] + $gross;
        if (! $profile['withholds'] || ! $profile['permanent'] || $profile['ptkp'] === null) {
            return ['method' => 'none', 'category' => null, 'rate' => null, 'taxable_gross' => $monthGross, 'tax' => 0, 'annual' => null];
        }
        $category = Pph21::category($profile['ptkp']);
        if (! $profile['last_month']) {
            $rate = Pph21::terRate($category, $monthGross);

            return ['method' => 'ter', 'category' => $category, 'rate' => $rate, 'taxable_gross' => $monthGross,
                'tax' => max(0, Pph21::terTax($monthGross, $rate) - $ytd['month_tax']), 'annual' => null];
        }

        $annual = $this->annual($profile, $month, $ytd['gross'] + $monthGross, $ytd['deductible'] + $ytd['month_deductible'] + $deductible);
        $annual['withheld_before'] = $ytd['tax'] + $ytd['month_tax'];

        return ['method' => 'annual', 'category' => $category, 'rate' => null, 'taxable_gross' => $monthGross,
            'tax' => $annual['tax'] - (int) $profile['previous_tax'] - $annual['withheld_before'], 'annual' => $annual];
    }

    /**
     * The year's tax on the year's figures (the A1 slip's calculation).
     *
     * @param  array<string, mixed>  $profile
     * @return array{gross: int, months: int, biaya_jabatan: int, deductible: int, net: int, previous_net: int, net_year: int, ptkp: int, pkp: int, tax: int}
     */
    public function annual(array $profile, int $lastMonth, int $grossYear, int $deductible): array
    {
        $months = max(1, $lastMonth - max(1, (int) $profile['first_month']) + 1);
        $biayaJabatan = Pph21::biayaJabatan($grossYear, $months);
        $net = $grossYear - $biayaJabatan - $deductible;
        $netYear = $net + (int) $profile['previous_income'];
        $ptkp = Pph21::ptkp((string) $profile['ptkp']);
        $pkp = Pph21::pkp($netYear, $ptkp);

        return ['gross' => $grossYear, 'months' => $months, 'biaya_jabatan' => $biayaJabatan, 'deductible' => $deductible, 'net' => $net,
            'previous_net' => (int) $profile['previous_income'], 'net_year' => $netYear, 'ptkp' => $ptkp, 'pkp' => $pkp, 'tax' => Pph21::article17($pkp)];
    }
}
