<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Credit\CreditStatus;
use DomainException;

class CreditLimitExceededException extends DomainException
{
    public function __construct(public readonly CreditStatus $status)
    {
        parent::__construct(implode(' ', $status->blockers));
    }
}
