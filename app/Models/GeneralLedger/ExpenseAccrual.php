<?php

namespace App\Models\GeneralLedger;

use App\Domain\Approval\RequiresApproval;
use App\Domain\CashBank\LineTax;
use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Domain\Settlement\Contracts\PaidByPayment;
use App\Domain\Settlement\SettlementService;
use App\Models\Company\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Expenses booked against a payable account, paid later by a payment line that settles it. */
class ExpenseAccrual extends Model implements PaidByPayment, Postable
{
    use PostsToLedger;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'due_date' => 'date', 'total' => 'integer', 'paid_amount' => 'integer', 'is_printed' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseAccrualLine::class)->orderBy('sort');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $total = 0;
        $inclusive = (bool) $this->inclusive_tax;
        foreach ($this->lines()->with('taxCode')->get() as $line) {
            // The expense without its tax; the tax to the tax code's VAT-in account.
            $net = LineTax::net($line, $inclusive);
            $builder->debit($line->account_id, $net, $line->memo, $line->branch_id, Tags::of($line));
            if ((int) $line->tax_amount !== 0) {
                $builder->debit(Accounts::vatIn($line->taxCode), (int) $line->tax_amount, $line->tax_invoice_number ? "VAT in {$line->tax_invoice_number}" : 'VAT in', $line->branch_id, Tags::of($line));
            }
            $total += $net + (int) $line->tax_amount;
        }
        $builder->credit($this->payable_account_id, $total, $this->description);
    }

    public function refreshTotal(): void
    {
        $this->forceFill(['total' => LineTax::refresh($this)])->saveQuietly();
        app(SettlementService::class)->refresh($this);
    }

    public function settlementAccountId(): int
    {
        return (int) $this->payable_account_id;
    }

    public function balance(): int
    {
        return $this->total - $this->paid_amount;
    }
}
