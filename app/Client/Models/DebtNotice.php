<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The aging notice an invoice got: when, to whom. Written once per invoice. */
class DebtNotice extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_to' => 'array', 'sent_at' => 'datetime', 'created_at' => 'datetime', 'days' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
