<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use App\Models\Company\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Check-in: a salesperson's visit to a customer, where and when, and the order it led to. */
class CheckIn extends Model implements HasAuditReference
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'trans_date' => 'date', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
