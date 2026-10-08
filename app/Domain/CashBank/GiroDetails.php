<?php

declare(strict_types=1);

namespace App\Domain\CashBank;

/** What a document says about its giro. */
final class GiroDetails
{
    public function __construct(
        public readonly string $direction,
        public readonly string $number,
        public readonly ?string $dueDate,
        public readonly int $amount,
        public readonly ?string $partyName = null,
        public readonly ?int $partyId = null,
    ) {}
}
