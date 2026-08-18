<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Giro;
use App\Models\Invoice;
use App\Models\PaymentEntry;

/**
 * What customers owe. One definition of it — and one deliberate exception.
 *
 * Three places needed this figure and each had computed it for itself: the
 * credit check, the ledger's Piutang Usaha reconciliation, and the invoice
 * row. That was survivable while the answer was "billed less paid" — it is the
 * same short subtraction three times. Credit notes made it a third term, and a
 * third term is where three copies start disagreeing: miss it in the credit
 * check and a customer who returned half an order still cannot reorder; miss
 * it in the reconciliation and the control account drifts from the subledger
 * every time somebody sends goods back.
 *
 * So it lives here, once, for the same reason resolvePrice() does.
 *
 * ## Where the one answer becomes two
 *
 * A bilyet giro splits this question in half, and the two halves genuinely
 * have different answers.
 *
 * When a customer hands over a giro, the books move the balance out of Piutang
 * Usaha into Piutang Giro — it is backed by a signed instrument now, and the
 * neraca should say so. So **the control account figure has to subtract it**,
 * or the reconciliation fails the moment anybody takes a cheque.
 *
 * The credit check must not. A giro is a promise with a date on it that can
 * bounce, and freeing a customer's limit the day they hand one over is exactly
 * how a customer who bounces giros keeps ordering. **So exposure goes on
 * counting it** until the day it clears, which is the day it becomes money.
 *
 * Two figures, named apart, both here where the difference is visible — rather
 * than one figure that is subtly wrong for one of its callers.
 *
 * Void invoices are excluded rather than netted: a voided invoice was never
 * owed. Drafts of credit notes are excluded too — a draft is an intention, and
 * letting one reduce a balance would credit a return nobody agreed to.
 */
class OutstandingReceivables
{
    /**
     * What the ledger's Piutang Usaha must equal.
     *
     * Net of giro in hand, because the ledger moved that balance to Piutang
     * Giro. This is **not** the total customers owe — that is this plus the
     * giro — and it is not what the credit check spends.
     */
    public function total(): int
    {
        return $this->invoiced(null)
            - $this->paid(null)
            - $this->credited(null)
            - $this->giroHeld(null);
    }

    /**
     * Everything one customer owes, however it is evidenced. What the credit
     * check spends.
     *
     * Deliberately **not** net of giro. See the note on this class.
     */
    public function forCompany(Company $company): int
    {
        return $this->invoiced($company->id)
            - $this->paid($company->id)
            - $this->credited($company->id);
    }

    /**
     * The same figure, but never negative.
     *
     * A customer who has overpaid or been over-credited has negative exposure,
     * which is arithmetically true and is not extra credit to spend. The
     * credit check wants this form; the reconciliation wants the signed one,
     * because a control account that quietly clamps at zero cannot be proved
     * against anything.
     */
    public function exposureFor(Company $company): int
    {
        return max(0, $this->forCompany($company));
    }

    private function invoiced(?int $companyId): int
    {
        return (int) Invoice::query()
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('total_rupiah');
    }

    /**
     * Payments count whether or not they have been matched to an invoice.
     *
     * Money in the bank is money the customer no longer owes, even while
     * finance is still working out which invoice it was for. Counting only
     * matched payments would hold a customer's credit hostage to a
     * reconciliation queue.
     */
    private function paid(?int $companyId): int
    {
        return (int) PaymentEntry::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('amount_rupiah');
    }

    private function credited(?int $companyId): int
    {
        return (int) CreditNote::query()
            ->posted()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('total_rupiah');
    }

    /**
     * Face value of customer giro we hold that has not cleared or bounced.
     *
     * Public because the ageing report needs it as its own column: a customer
     * who owes 44 million of which 20 is covered by paper due next Tuesday is
     * a different conversation from one who owes 44 million and has sent
     * nothing.
     */
    public function giroHeld(?int $companyId): int
    {
        return (int) Giro::query()
            ->open()
            ->masuk()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('nilai_rupiah');
    }
}
