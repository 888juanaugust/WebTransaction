<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/** The common part of a priced line: its parent, its remaining quantity. */
trait DocumentLine
{
    abstract public function document(): Model;

    public function remainingQuantity(): string
    {
        return (string) BigDecimal::of((string) $this->base_quantity)->minus((string) ($this->processed_quantity ?? 0))->toScale(4);
    }

    public function isFullyProcessed(): bool
    {
        return ! BigDecimal::of($this->remainingQuantity())->isPositive();
    }
}
