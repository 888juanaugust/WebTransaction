<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a basket: a SKU, a unit and how many.
 *
 * No price column, and no qty_base either. Base quantity is derived from the
 * product at checkout, so a carton size changed while the item sat in a basket
 * is picked up rather than baked in.
 */
#[Fillable(['cart_id', 'sku', 'ordered_unit', 'ordered_qty'])]
class CartItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordered_unit' => Unit::class,
            'ordered_qty' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /**
     * Quantity in base units, derived live.
     *
     * Returns null when the conversion is not valid — asking for SET of a PCS
     * product, or by the carton when qty_per_ctn is unknown. The cart shows
     * that as a problem to fix rather than guessing a number.
     */
    public function baseQuantity(): ?int
    {
        $product = $this->product;

        if ($product === null) {
            return null;
        }

        try {
            return $this->ordered_unit->toBaseQtyForProduct($this->ordered_qty, $product);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
