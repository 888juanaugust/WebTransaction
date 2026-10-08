<?php

namespace App\Models\CashBank;

use App\Domain\Approval\RequiresApproval;
use App\Domain\CashBank\Contracts\GiroSource;
use App\Domain\CashBank\GiroDetails;
use App\Domain\CashBank\LineTax;
use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Receipt: other money into a cash or bank account, credited to any
 * accounts (K-03). Received by giro, it debits giros receivable until the
 * giro clears.
 */
class CashReceipt extends Model implements GiroSource, Postable
{
    use PostsToLedger;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'cheque_date' => 'date', 'amount' => 'integer', 'is_printed' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashReceiptLine::class)->orderBy('sort');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function giro(): MorphOne
    {
        return $this->morphOne(Giro::class, 'source');
    }

    public function refreshTotal(): void
    {
        $this->forceFill(['amount' => LineTax::refresh($this)])->saveQuietly();
    }

    public function giroDetails(): ?GiroDetails
    {
        if (blank($this->cheque_no)) {
            return null;
        }

        return new GiroDetails(Giro::IN, (string) $this->cheque_no, $this->cheque_date?->toDateString(), (int) $this->amount, $this->payer);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $total = 0;
        $inclusive = (bool) $this->inclusive_tax;
        foreach ($this->lines()->with('taxCode')->get() as $line) {
            // The income without its tax; the tax to the tax code's VAT-out account.
            $net = LineTax::net($line, $inclusive);
            $builder->signed($line->account_id, -$net, $line->memo, $line->branch_id, Tags::of($line));
            if ((int) $line->tax_amount !== 0) {
                $builder->signed(Accounts::vatOut($line->taxCode), -(int) $line->tax_amount, 'VAT out', $line->branch_id, Tags::of($line));
            }
            $total += $net + (int) $line->tax_amount;
        }
        $debit = $this->giroDetails() !== null ? Accounts::giroReceivable() : $this->bank_account_id;
        $builder->debit($debit, $total, $this->description ?: ($this->payer ? "Receipt from {$this->payer}" : null));
    }
}
