<?php

declare(strict_types=1);

namespace App\Domain\Integrity;

/**
 * One thing that stopped adding up, in the region where it stopped.
 *
 * Deliberately flat and already in words: these are read at 03:00 by whoever
 * the alert reached, and a finding that needs the reader to go and join two
 * tables before it means anything is a finding that waits until Monday.
 */
final readonly class IntegrityFinding
{
    public function __construct(
        /** Which check produced it — stok, reservasi, nilai_persediaan, buku. */
        public string $pemeriksaan,
        public string $wilayah,
        /** What is out, named: a SKU, an account code. */
        public string $subjek,
        /** What the cache or the ledger says, against what it should say. */
        public string $temuan,
    ) {}
}
