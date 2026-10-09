<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Integrity;

/** One place where a cache and its ledger disagree: which check, which row, what differs. */
final readonly class IntegrityFinding
{
    public function __construct(public string $check, public string $subject, public string $detail) {}

    public function line(): string
    {
        return "[{$this->check}] {$this->subject}: {$this->detail}";
    }
}
