<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
        public readonly int $warehouseId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            "Stok {$sku} tidak cukup di gudang {$warehouseId}: diminta {$requested}, tersedia {$available}."
        );
    }
}
