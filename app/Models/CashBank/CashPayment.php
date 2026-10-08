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
use App\Domain\Settlement\Contracts\PaidByPayment;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Format;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RuntimeException;

/**
 * Payment: money out of a cash or bank account to any accounts, one line
 * each (K-02). A line may settle an expense accrual or a payroll entry: it
 * then debits that document's own payable account and is allocated to it,
 * never more than is open. Paid by giro, it credits giros payable until the
 * giro clears.
 */
class CashPayment extends Model implements GiroSource, Postable
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
        return $this->hasMany(CashPaymentLine::class)->orderBy('sort');
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
        $this->forceFill(['amount' => LineTax::refresh($this, fn ($line) => filled($line->payable_type))])->saveQuietly();
    }

    public function giroDetails(): ?GiroDetails
    {
        if (blank($this->cheque_no)) {
            return null;
        }

        return new GiroDetails(Giro::OUT, (string) $this->cheque_no, $this->cheque_date?->toDateString(), (int) $this->amount, $this->payee);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $settlement = app(SettlementService::class);
        $total = 0;
        $inclusive = (bool) $this->inclusive_tax;
        foreach ($this->lines()->with(['payable', 'taxCode'])->get() as $line) {
            $amount = (int) $line->amount;
            $payable = $line->payable;
            if ($payable instanceof PaidByPayment) {
                // The earlier revision of this payment is already superseded, so the balance is what is open without it.
                $open = $settlement->balance($payable);
                if ($amount > $open) {
                    throw new RuntimeException(__(':number has :open open; a payment line cannot settle more.', ['number' => $payable->number, 'open' => Format::money($open)]));
                }
                $builder->signed($payable->settlementAccountId(), $amount, $line->memo ?: $payable->number, $line->branch_id);
                $builder->allocate(['receivable_type' => $line->payable_type, 'receivable_id' => $line->payable_id, 'amount' => $amount, 'discount' => 0]);
            } else {
                // The expense without its tax; the tax to the tax code's VAT-in account.
                $builder->signed($line->account_id, LineTax::net($line, $inclusive), $line->memo, $line->branch_id, Tags::of($line));
                if ((int) $line->tax_amount !== 0) {
                    $builder->signed(Accounts::vatIn($line->taxCode), (int) $line->tax_amount, $line->tax_invoice_number ? "VAT in {$line->tax_invoice_number}" : 'VAT in', $line->branch_id, Tags::of($line));
                }
                $amount = LineTax::net($line, $inclusive) + (int) $line->tax_amount;
            }
            $total += $amount;
        }
        $credit = $this->giroDetails() !== null ? Accounts::giroPayable() : $this->bank_account_id;
        $builder->credit($credit, $total, $this->description ?: ($this->payee ? "Payment to {$this->payee}" : null));
    }
}
