<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\PayrollEntry;
use App\Models\Company\PayrollEntryLine;
use App\Models\Company\SalaryComponent;
use App\Modules\BaseModule;
use Database\Seeders\Defaults\SalaryComponentSeeder;

/** Salary components, payroll entries and the income-tax forms. Off until a company runs payroll here. */
final class PayrollModule extends BaseModule
{
    public static function key(): string
    {
        return 'payroll';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::Payroll;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::SalaryComponents, MenuKey::PayrollEntries, MenuKey::IncomeTaxArt21Return, MenuKey::WithholdingSlips];
    }

    public static function morphMap(): array
    {
        return ['salary_component' => SalaryComponent::class, 'payroll_entry' => PayrollEntry::class, 'payroll_entry_line' => PayrollEntryLine::class];
    }

    public static function defaultSeeders(): array
    {
        return [SalaryComponentSeeder::class];
    }
}
