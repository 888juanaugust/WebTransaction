<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * One control account, checked against the subledger it summarises.
 */
final readonly class ControlAccountCheck
{
    public function __construct(
        public string $kode,
        public string $nama,
        public int $buku,
        public int $subledger,
        public string $sumber,
    ) {}

    public function agrees(): bool
    {
        return $this->buku === $this->subledger;
    }

    /** Ledger minus subledger. The sign says which way the books are out. */
    public function selisih(): int
    {
        return $this->buku - $this->subledger;
    }
}
