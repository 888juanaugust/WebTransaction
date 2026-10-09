<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Sales\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a return claim: so much of one invoice line comes back. */
class ReturnClaimLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'base_quantity' => 'decimal:4'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ReturnClaim::class, 'return_claim_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
