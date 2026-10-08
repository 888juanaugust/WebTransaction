<?php

namespace App\Models\Sales;

use App\Domain\Documents\DocumentLine;
use App\Models\Company\Employee;
use App\Models\Company\TaxCode;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryLine extends Model
{
    use DocumentLine;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'base_quantity' => 'decimal:4', 'processed_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:4',
            'discount_amount' => 'integer', 'header_discount' => 'integer', 'amount' => 'integer', 'dpp_amount' => 'integer', 'tax_amount' => 'integer',
        ];
    }

    public function document(): Model
    {
        return $this->delivery;
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    /** The line amount after its share of the discount on the total, before tax: what it is worth to the books. */
    public function netAmount(): int
    {
        return (int) $this->amount - (int) $this->header_discount - ((bool) $this->delivery->inclusive_tax ? (int) $this->tax_amount : 0);
    }
}
