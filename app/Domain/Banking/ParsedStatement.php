<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * What the parser got out of a statement file: the rows it understood and the
 * rows it did not — both kept, because a mutasi with silently missing lines
 * would make the reconciliation lie with confidence.
 *
 * @phpstan-type ParsedRow array{urutan: int, tanggal: string, uraian: string,
 *     arah: string, amount_rupiah: int, saldo_rupiah: ?int}
 * @phpstan-type ErrorRow array{urutan: int, uraian: string, sebab: string}
 */
final class ParsedStatement
{
    /**
     * @param  list<array{urutan: int, tanggal: string, uraian: string, arah: string, amount_rupiah: int, saldo_rupiah: ?int}>  $rows
     * @param  list<array{urutan: int, uraian: string, sebab: string}>  $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->errors === [];
    }
}
