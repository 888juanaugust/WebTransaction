<?php

namespace Database\Seeders\Defaults;

use App\Domain\Numbering\TransactionType;
use App\Models\Company\TransactionApprover;
use App\Models\Settings\AccessGroup;
use Illuminate\Database\Seeder;

/**
 * Approval rules a company often wants for credits it gives or claims: sales
 * returns, purchase returns and vendor claims, approved by any one member of
 * Accounting. Seeded switched off, so nothing waits until the company turns a
 * rule on; a type that already has a rule is left alone.
 */
class ApprovalRuleSeeder extends Seeder
{
    public function run(): void
    {
        $accounting = AccessGroup::query()->where('name', 'Accounting')->first();
        foreach ([TransactionType::SalesReturn, TransactionType::PurchaseReturn, TransactionType::VendorClaim] as $type) {
            if (TransactionApprover::query()->where('transaction_type', $type->value)->exists()) {
                continue;
            }
            $rule = TransactionApprover::query()->create(['transaction_type' => $type->value, 'min_amount' => 0, 'rule' => TransactionApprover::ANY_ONE, 'is_active' => false]);
            if ($accounting !== null) {
                $rule->groups()->attach($accounting->id, ['sort' => 0]);
            }
        }
    }
}
