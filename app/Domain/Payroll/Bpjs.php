<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Shared\Money;
use Carbon\CarbonInterface;

/**
 * The month's BPJS contributions on an employee's wage, from
 * config('payroll.bpjs'): health (capped), old-age savings, pension (capped
 * by the cap in force on the pay date), work accident and death. Pure.
 */
final class Bpjs
{
    /**
     * @param  array{bpjs_health: bool, bpjs_employment: bool, jp_participant: bool, jkk_rate: string}  $profile
     * @return list<array{fee_type: string, memo: string, amount: int}>
     */
    public static function contributions(int $wage, array $profile, CarbonInterface $payDate): array
    {
        $rules = (array) config('payroll.bpjs');
        $lines = [];
        $add = function (string $feeType, string $memo, int $base, string $rate) use (&$lines): void {
            $amount = Money::percent($base, $rate);
            if ($amount !== 0) {
                $lines[] = ['fee_type' => $feeType, 'memo' => $memo, 'amount' => $amount];
            }
        };
        if ($wage <= 0) {
            return [];
        }
        if ($profile['bpjs_health']) {
            $base = min($wage, (int) $rules['health']['cap']);
            $add('health_premium_employer', __('BPJS Health, employer :rate%', ['rate' => $rules['health']['employer']]), $base, (string) $rules['health']['employer']);
            $add('health_premium_employee', __('BPJS Health, employee :rate%', ['rate' => $rules['health']['employee']]), $base, (string) $rules['health']['employee']);
        }
        if ($profile['bpjs_employment']) {
            $add('pension_employer', __('JHT, employer :rate%', ['rate' => $rules['jht']['employer']]), $wage, (string) $rules['jht']['employer']);
            $add('pension_employee', __('JHT, employee :rate%', ['rate' => $rules['jht']['employee']]), $wage, (string) $rules['jht']['employee']);
            if ($profile['jp_participant']) {
                $base = min($wage, self::pensionCap($payDate));
                $add('pension_employer', __('JP, employer :rate%', ['rate' => $rules['jp']['employer']]), $base, (string) $rules['jp']['employer']);
                $add('pension_employee', __('JP, employee :rate%', ['rate' => $rules['jp']['employee']]), $base, (string) $rules['jp']['employee']);
            }
            $add('accident_insurance', __('JKK :rate%', ['rate' => $profile['jkk_rate']]), $wage, $profile['jkk_rate']);
            $add('death_insurance', __('JKM :rate%', ['rate' => $rules['jkm']['employer']]), $wage, (string) $rules['jkm']['employer']);
        }

        return $lines;
    }

    /** The pension wage cap in force on a date (the latest dated one on or before it). */
    public static function pensionCap(CarbonInterface $date): int
    {
        $cap = PHP_INT_MAX;
        $caps = (array) config('payroll.bpjs.jp.caps');
        ksort($caps);
        foreach ($caps as $from => $value) {
            if ($date->toDateString() >= $from) {
                $cap = (int) $value;
            }
        }

        return $cap === PHP_INT_MAX ? (int) reset($caps) : $cap;
    }
}
