<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Staff roles. The hard rule this encodes: whoever confirms a payment must not
 * be able to edit the invoice amount.
 */
enum Role: string
{
    case Sales = 'sales';
    case Warehouse = 'warehouse';
    case Finance = 'finance';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Warehouse => 'Gudang',
            self::Finance => 'Keuangan',
            self::Owner => 'Pemilik',
        };
    }

    /** Sales quote prices; warehouse must never see them. */
    public function canSeePrices(): bool
    {
        return $this !== self::Warehouse;
    }

    /** Warehouse is deliberately blind to credit and AR data. */
    public function canSeeCreditData(): bool
    {
        return in_array($this, [self::Sales, self::Finance, self::Owner], true);
    }

    /**
     * Purchase cost, average cost, inventory value, and therefore margin.
     *
     * Tighter than canSeeCreditData() by one role, and the missing role is
     * Sales. What a customer pays is a salesperson's job; what we paid is not.
     * Cost plus selling price is margin, and margin in the hands of whoever
     * negotiates the discount changes how the discount gets negotiated.
     *
     * Warehouse is excluded for the same reason it is excluded everywhere else.
     */
    public function canSeeCost(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Record goods arriving, and post the receipt that values them.
     *
     * Finance and Owner, because the document is entered from the supplier's
     * invoice and carries what we paid on every line.
     *
     * This is a compromise worth naming: the person who physically counts the
     * cartons is warehouse staff, and they cannot enter this. Splitting it —
     * warehouse records quantities, finance attaches costs and posts — is the
     * right shape and is not built. Until it is, receipts are entered from the
     * paperwork rather than from the loading bay.
     */
    public function canRecordPurchases(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Write a manual journal, or reverse one.
     *
     * Entries posted by documents need no permission — they are consequences
     * of actions already authorised elsewhere. This covers the entries with no
     * document behind them: opening balances, accruals, an accountant's
     * correction. Those are assertions about the business made on somebody's
     * say-so, and only Finance and the Owner get to say so.
     */
    public function canPostJournals(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /** Neraca, laba rugi, trial balance, the journal register. */
    public function canSeeBooks(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Close a month, locking it against anything further being posted into it.
     *
     * Finance's job — they are the ones who reconcile it and hand the figures
     * over, so they are the ones who know when it is done.
     */
    public function canClosePeriod(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Reopen a closed month.
     *
     * Owner only, and deliberately narrower than closing it. Reopening is how
     * a set of figures that has already gone to the accountant gets quietly
     * restated, so the person who closed the month should not be able to undo
     * that alone — the same reasoning that keeps whoever confirms a payment
     * away from the invoice amount.
     */
    public function canReopenPeriod(): bool
    {
        return $this === self::Owner;
    }

    public function canCreateOrders(): bool
    {
        return in_array($this, [self::Sales, self::Owner], true);
    }

    /** Finance confirms money in. Sales never does. */
    public function canConfirmPayment(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Deliberately disjoint from canConfirmPayment() for Finance: the person
     * who confirms a payment must not be able to move the amount owed.
     */
    public function canEditOrderPrices(): bool
    {
        return in_array($this, [self::Sales, self::Owner], true);
    }

    /**
     * Issue a credit note — a return, or a correction to what a customer owes.
     *
     * Follows canEditOrderPrices() rather than canConfirmPayment(), and the
     * distinction is the whole point. A credit note reduces the amount owed,
     * which is editing the invoice amount by another name. CLAUDE.md's hard
     * rule is that whoever confirms a payment must not be able to do that, and
     * the fraud it blocks is the ordinary one: take a customer's payment,
     * keep it, then write the receivable off as a return nobody witnessed.
     *
     * So Finance — who confirm payments — cannot issue credit notes, and Sales
     * — who cannot touch money coming in — can.
     */
    public function canIssueCreditNote(): bool
    {
        return in_array($this, [self::Sales, self::Owner], true);
    }

    public function canOverrideCreditLimit(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Move stock between our own warehouses.
     *
     * Warehouse work, and no money control is needed: a transfer is
     * value-neutral by construction, so there is nothing to give away. What
     * matters is that whoever moved the cartons is the one who says so.
     */
    public function canTransferStock(): bool
    {
        return in_array($this, [self::Warehouse, self::Owner], true);
    }

    /** Draw up a count sheet and write down what is on the shelf. */
    public function canCountStock(): bool
    {
        return in_array($this, [self::Warehouse, self::Owner], true);
    }

    /**
     * Sign off a count variance, writing the difference to the books.
     *
     * Deliberately disjoint from canCountStock() for Warehouse. A stock count
     * is the one document whose purpose is to make missing goods disappear
     * from the record, and the person who counted the shelf must not be the
     * person who approves what they found. Finance and Owner approve; the
     * poster refuses to let one person do both.
     */
    public function canApproveStockCount(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Open the reports.
     *
     * Everybody who sells or keeps the books. Warehouse is excluded for the
     * usual reason — every report here is money — and what the others see
     * inside them still differs: `canSeeCost()` decides whether margin
     * columns exist at all, so Sales get what they sold without what it cost.
     */
    public function canSeeReports(): bool
    {
        return in_array($this, [self::Sales, self::Finance, self::Owner], true);
    }

    /**
     * Export fakturs for filing, and record the serial numbers that come back.
     *
     * Finance's job, and Owner's. It is not an accounting entry — nothing here
     * posts to the ledger, and an NSFP changes no figure — but it is the
     * output VAT return, and the person who reconciles PPN Keluaran is the
     * person who should be filing it.
     *
     * Sales are excluded even though they issue the invoices behind it. Filing
     * is a statement to the tax office about what was sold, and the people
     * paid on what was sold should not be the ones making it.
     */
    public function canExportFaktur(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Spread a freight or duty charge over the goods it belongs to.
     *
     * Follows canRecordPurchases rather than canApproveStockCount, because
     * this is a bookkeeping judgement about a supplier invoice and not a
     * check on somebody else's work. Warehouse is excluded for the ordinary
     * reason: the whole document is money.
     *
     * There is no counter/approver split here and there should not be one. An
     * allocation moves cost between two places we already own it — inventory
     * and cost of sales — and creates no payable, no stock and no way out for
     * anything. The fraud that opname's split defends against has no analogue.
     */
    public function canAllocateLandedCost(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Reconcile the bank account against a statement.
     *
     * Finance and Owner, following the books rather than payment authority —
     * this posts adjustments and asserts that a balance is real, which is
     * bookkeeping judgement.
     *
     * It is worth naming what this control does *not* do. Everywhere else in
     * this system the dangerous pair is separated: whoever confirms a payment
     * cannot move an invoice amount, whoever counts stock cannot approve the
     * variance. Here the person who records payments is also the person who
     * proves the bank balance, which is the classic segregation failure — and
     * it is unavoidable in a company with one finance clerk. What stands in
     * for the split is that the statement balance comes from outside: they can
     * mis-tick a line, but they cannot make the bank's own closing figure
     * agree with a book they have fiddled. The Owner reading a finalised
     * reconciliation is the real control, which is why it records who
     * finalised it and when.
     */
    public function canReconcileBank(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Register a bilyet giro, bank it, and record whether it cleared.
     *
     * Follows canConfirmPayment(), because that is what this is: a giro
     * clearing *is* a payment, and it goes through the same ledger. Receiving
     * the paper is money handling too — somebody is walking a negotiable
     * instrument into the office and putting it in a drawer.
     *
     * Sales are excluded even though they are usually the ones handed the
     * giro at the counter. CLAUDE.md's hard rule is that whoever confirms a
     * payment must not be able to move what a customer owes, and a giro is
     * both at once: recording one changes the books, and recording a bounce
     * changes them back. Sales see the giro on the customer's record; they do
     * not decide its fate.
     */
    public function canHandleGiro(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Send goods back to a supplier.
     *
     * Follows canRecordPurchases(), because this is the receipt read backwards
     * — it carries the same cost on every line and is entered from the same
     * paperwork. It inherits that capability's compromise too: the person who
     * physically hands the cartons back to the driver is warehouse staff, and
     * they enter nothing.
     *
     * Deliberately *not* split into a raise/approve pair like the stock count.
     * It is worth being explicit about why, because a return does write stock
     * off the shelf and that is exactly what opname's split defends against.
     * The difference is that a return has a counterparty: the goods go to a
     * named supplier against a named delivery, the document is sent to them,
     * and what comes back is their credit note. A count variance is a private
     * claim about a shelf; a return is a claim somebody else has to agree
     * with. `nomor_nota_kredit_supplier` sitting empty is what makes an
     * invented one visible.
     */
    public function canReturnToSupplier(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    public function canPickAndShip(): bool
    {
        return in_array($this, [self::Warehouse, self::Owner], true);
    }

    public function canViewAuditLog(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Create staff accounts, change what they can do, and shut them off.
     *
     * Owner only, and it is the widest permission in this enum by some margin
     * — not because the screen shows anything sensitive, but because it is the
     * one place where every other line above can be granted to somebody. A
     * Finance clerk who can hand themselves the Sales role has just undone the
     * rule that keeps whoever confirms a payment away from the invoice amount,
     * and they can do it in the time it takes to load one page.
     *
     * So the separations elsewhere are only worth what this line is worth.
     */
    public function canManageStaff(): bool
    {
        return $this === self::Owner;
    }
}
