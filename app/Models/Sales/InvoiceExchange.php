<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Invoice Exchange: the receipt for invoices handed to a customer for collection on a date. Not posted. */
class InvoiceExchange extends Model implements HasAuditReference
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'collect_date' => 'date', 'due_date' => 'date', 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceExchangeLine::class)->orderBy('sort');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function refreshTotal(): void
    {
        $invoices = SalesInvoice::query()->whereIn('id', $this->lines()->pluck('sales_invoice_id'))->get();
        $this->forceFill([
            'total' => (int) $invoices->sum('total'),
            'status' => $invoices->isNotEmpty() && $invoices->every(fn ($i) => $i->payment_status === 'paid') ? 'processed' : 'pending',
        ])->saveQuietly();
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
