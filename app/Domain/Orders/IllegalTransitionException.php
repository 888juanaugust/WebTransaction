<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use DomainException;

class IllegalTransitionException extends DomainException
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct(
            "Order tidak bisa berpindah dari {$from->value} ke {$to->value}."
        );
    }
}
