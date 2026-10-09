<?php

declare(strict_types=1);

namespace App\Client\Portal;

use App\Client\Models\CustomerUser;
use App\Models\Sales\Customer;
use RuntimeException;

/** The buyer in session, and their customer. */
final class Portal
{
    public static function buyer(): CustomerUser
    {
        $buyer = auth('customer')->user();
        if (! $buyer instanceof CustomerUser) {
            throw new RuntimeException(__('No buyer is signed in.'));
        }

        return $buyer;
    }

    public static function customer(): Customer
    {
        $customer = self::buyer()->customer;
        if ($customer === null) {
            throw new RuntimeException(__('This login belongs to no customer.'));
        }

        return $customer;
    }
}
