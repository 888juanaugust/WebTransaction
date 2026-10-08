<?php

namespace Database\Seeders\Defaults;

use App\Models\Company\SalaryComponent;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Seeder;

/** Salary components on the seeded salaries account, run when the Payroll module is on. */
class SalaryComponentSeeder extends Seeder
{
    public function run(): void
    {
        $salaries = Account::query()->where('no', '6100')->value('id');
        foreach ([['Basic salary', 'salary'], ['Overtime', 'overtime'], ['Holiday allowance (THR)', 'bonus'], ['Health insurance (employer)', 'health_premium_employer']] as [$name, $type]) {
            SalaryComponent::query()->firstOrCreate(['name' => $name], ['fee_type' => $type, 'expense_account_id' => $salaries, 'is_active' => true]);
        }
    }
}
