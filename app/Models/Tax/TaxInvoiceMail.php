<?php

namespace App\Models\Tax;

use App\Models\Sales\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the append-only tax invoice mail log: a send asked for (queued), or its outcome (sent, failed, skipped). */
class TaxInvoiceMail extends Model
{
    public const UPDATED_AT = null;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attachments' => 'array', 'resend' => 'boolean', 'created_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
