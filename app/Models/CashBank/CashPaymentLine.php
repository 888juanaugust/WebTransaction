<?php

namespace App\Models\CashBank;

use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashPaymentLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function cashPayment(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The accrual or payroll entry this line settles, if any. */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
