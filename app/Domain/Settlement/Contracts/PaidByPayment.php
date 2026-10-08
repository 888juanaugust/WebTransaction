<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Contracts;

/**
 * A document that owes money on an account of its own (an expense accrual, a
 * payroll entry) and is settled by a payment line pointing at it: the line
 * debits that account and allocates to the document.
 */
interface PaidByPayment
{
    /** The account the document credited and its payment debits. */
    public function settlementAccountId(): int;
}
