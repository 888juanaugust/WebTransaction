<?php

declare(strict_types=1);

namespace App\Client\Domain\Customers;

use App\Client\Models\CustomerType;
use App\Domain\Audit\Auditor;
use App\Models\Sales\Customer;
use App\Models\User;

/**
 * A customer takes the terms of its type: the tier, the payment term and
 * the credit age limit are copied onto the customer when the type is set
 * or changed (overwriting what was there), and again for every customer
 * of a type when the owner re-applies it. Each copy is audited with the
 * values before and after.
 */
final class CustomerTypeTerms
{
    /** Fills the type's terms onto the customer without saving; returns what changed (before → after), empty when nothing did. */
    public function fill(Customer $customer): array
    {
        $type = $customer->customer_type_id ? CustomerType::query()->find($customer->customer_type_id) : null;
        if ($type === null) {
            return [];
        }
        $diff = [];
        foreach ($type->terms() as $column => $value) {
            $before = $customer->getAttribute($column);
            if ($value === null && in_array($column, ['price_category_id', 'payment_term_id'], true)) {
                continue; // a type that leaves a term blank leaves the customer's own
            }
            if ((string) $before !== (string) $value) {
                $diff[$column] = ['before' => $before, 'after' => $value];
                $customer->setAttribute($column, $value);
            }
        }

        return $diff;
    }

    /** Re-applies the type's terms to every active customer of it, audited per customer; returns how many changed. */
    public function applyToAll(CustomerType $type, ?User $actor = null): int
    {
        $changed = 0;
        foreach ($type->customers()->get() as $customer) {
            $diff = $this->fill($customer);
            if ($diff === []) {
                continue;
            }
            $customer->saveQuietly();
            $this->audit($customer, $diff);
            $changed++;
        }

        return $changed;
    }

    /** @param  array<string, array{before: mixed, after: mixed}>  $diff */
    public function audit(Customer $customer, array $diff): void
    {
        Auditor::log('customer_type_applied', $customer, $customer->name, ['type' => $customer->customer_type_id, 'changes' => $diff]);
    }
}
