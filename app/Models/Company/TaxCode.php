<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Tax\TaxType;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCode extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            'rate_percent' => 'decimal:4',
            'dpp_numerator' => 'integer',
            'dpp_denominator' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** 12.0000 % → 1200 basis points. */
    public function rateBasisPoints(): int
    {
        return (int) round(((float) $this->rate_percent) * 100);
    }

    /** The burden on the price: 12 % with DPP 11/12 reads "11 %". */
    public function effectiveRatePercent(): float
    {
        return (float) $this->rate_percent * $this->dpp_numerator / max(1, $this->dpp_denominator);
    }

    public function salesTaxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_tax_account_id');
    }

    public function purchaseTaxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'purchase_tax_account_id');
    }

    public function auditReference(): string
    {
        return $this->description;
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->first();
    }
}
