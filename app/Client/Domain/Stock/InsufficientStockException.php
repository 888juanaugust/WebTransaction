<?php

declare(strict_types=1);

namespace App\Client\Domain\Stock;

use App\Domain\Shared\Format;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use RuntimeException;

/** An order asks for more than a warehouse has free (on hand less what other orders hold). */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly Item $item, public readonly Warehouse $warehouse, public readonly string $short, public readonly string $available)
    {
        parent::__construct(__('Not enough free stock of :item in :warehouse: :available available, :short short. Other orders hold the rest.', [
            'item' => $item->name,
            'warehouse' => $warehouse->name,
            'available' => Format::quantity($available),
            'short' => Format::quantity($short),
        ]));
    }
}
