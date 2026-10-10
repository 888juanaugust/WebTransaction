<?php

declare(strict_types=1);

namespace App\Client\Domain\Customers;

use App\Models\Sales\Customer;

/**
 * The address goods go to: the customer's shipping address, or the billing
 * address while the two are the same. The one rule the panel's customer
 * picker applies, shared with the portal so a buyer's order ships to the
 * right door.
 */
final class ShipTo
{
    public static function of(Customer $customer): string
    {
        if ($customer->ship_same_as_bill) {
            return $customer->billAddress();
        }

        return collect([$customer->ship_street, $customer->ship_city, $customer->ship_province, $customer->ship_zip_code])->filter()->join(', ');
    }
}
