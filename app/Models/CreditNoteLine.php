<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a credit note.
 *
 * Everything on it is a snapshot: the price comes from the order line being
 * credited, not from today's price list, and the cost comes from the shipment
 * that sent the goods out, not from today's average. A return credited at a
 * price that has since changed refunds a number the customer never paid; a
 * return valued at today's average invents margin.
 */
#[Fillable([
    'credit_note_id', 'order_line_id', 'sku', 'urutan', 'deskripsi',
    'ordered_unit', 'ordered_qty', 'qty_per_ctn_snapshot', 'qty_base',
    'unit_price_rupiah', 'line_total_rupiah', 'dpp_rupiah', 'ppn_rupiah',
    'unit_cost_rupiah', 'line_cost_rupiah',
])]
class CreditNoteLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordered_unit' => Unit::class,
            'ordered_qty' => 'integer',
            'qty_per_ctn_snapshot' => 'integer',
            'qty_base' => 'integer',
            'unit_price_rupiah' => 'integer',
            'line_total_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'line_cost_rupiah' => 'integer',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }
}
