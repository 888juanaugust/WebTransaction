<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Money out, append-only — the mirror of PaymentLedger.
 *
 * A payment row is never mutated. Corrections are reversing entries: same
 * amount, opposite sign, pointing back at what they undo. The bill's total is
 * never touched by any of it, which is the whole control: whoever pays cannot
 * move the amount owed.
 */
class SupplierLedger
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentPoster $poster,
    ) {}

    /**
     * Record a payment made to a supplier.
     *
     * There is no gateway on this side — we pay suppliers by bank transfer, and
     * somebody types what left the account. That makes the actor mandatory:
     * every rupiah out has a person's name against it.
     */
    public function recordPayment(
        Supplier $supplier,
        int $amountRupiah,
        User $actor,
        ?SupplierBill $bill = null,
        ?string $referensi = null,
        ?string $catatan = null,
        ?DateTimeInterface $paidAt = null,
    ): SupplierPaymentEntry {
        if ($amountRupiah <= 0) {
            throw new LogicException('A supplier payment must be positive.');
        }

        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Anda tidak berhak mencatat pembayaran ke pemasok.');
        }

        if ($bill !== null && $bill->supplier_id !== $supplier->id) {
            throw new LogicException('That bill belongs to a different supplier.');
        }

        return DB::transaction(function () use (
            $supplier, $amountRupiah, $actor, $bill, $referensi, $catatan, $paidAt
        ) {
            $entry = SupplierPaymentEntry::create([
                'supplier_id' => $supplier->id,
                'supplier_bill_id' => $bill?->id,
                'amount_rupiah' => $amountRupiah,
                'kind' => SupplierPaymentEntry::KIND_PAYMENT,
                'referensi' => $referensi,
                'actor_id' => $actor->id,
                'paid_at' => $paidAt ?? now(),
                'catatan' => $catatan,
            ]);

            $this->settleIfCleared($bill);

            // Dr Utang Usaha / Cr Bank.
            $this->poster->supplierPaymentMade($entry, $actor);

            $this->audit->log(
                action: 'supplier_payment_recorded',
                subject: $entry,
                newValue: [
                    'supplier_id' => $supplier->id,
                    'supplier_bill_id' => $bill?->id,
                    'amount_rupiah' => $amountRupiah,
                    'referensi' => $referensi,
                ],
                actor: $actor,
            );

            return $entry;
        });
    }

    /**
     * Undo a payment by appending its opposite. The original row stays.
     */
    public function reverse(SupplierPaymentEntry $entry, User $actor, string $alasan): SupplierPaymentEntry
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Anda tidak berhak membalik pembayaran.');
        }

        if ($entry->kind === SupplierPaymentEntry::KIND_REVERSAL) {
            throw new LogicException('A reversal cannot itself be reversed.');
        }

        return DB::transaction(function () use ($entry, $actor, $alasan) {
            $already = SupplierPaymentEntry::query()
                ->where('reverses_entry_id', $entry->id)
                ->exists();

            if ($already) {
                throw new LogicException("Entry {$entry->id} has already been reversed.");
            }

            $reversal = SupplierPaymentEntry::create([
                'supplier_id' => $entry->supplier_id,
                'supplier_bill_id' => $entry->supplier_bill_id,
                'amount_rupiah' => -$entry->amount_rupiah,
                'kind' => SupplierPaymentEntry::KIND_REVERSAL,
                'actor_id' => $actor->id,
                'reverses_entry_id' => $entry->id,
                'paid_at' => now(),
                'catatan' => $alasan,
            ]);

            /*
             * A reversal can take a settled bill back to open — the money was
             * never really there. Re-evaluating rather than assuming keeps the
             * status a function of the ledger instead of of the last action.
             */
            $bill = $entry->supplierBill;

            if ($bill !== null) {
                $bill->refresh();

                $bill->forceFill([
                    'status' => $bill->amountOutstanding() <= 0
                        ? SupplierBill::STATUS_PAID
                        : SupplierBill::STATUS_OPEN,
                ])->save();
            }

            $this->poster->supplierPaymentMade($reversal, $actor);

            $this->audit->log(
                action: 'supplier_payment_reversed',
                subject: $reversal,
                oldValue: ['entry_id' => $entry->id, 'amount_rupiah' => $entry->amount_rupiah],
                newValue: ['amount_rupiah' => $reversal->amount_rupiah, 'alasan' => $alasan],
                actor: $actor,
            );

            return $reversal;
        });
    }

    /** What we still owe a supplier across every open bill. */
    public function outstandingFor(Supplier $supplier): int
    {
        return $this->billed($supplier->id)
            - $this->paid($supplier->id)
            - $this->returned($supplier->id);
    }

    /**
     * Total accounts payable across every supplier.
     *
     * What the ledger's Utang Usaha must equal. Three terms, and the third one
     * is the one that is easy to forget: a purchase return of goods a supplier
     * had already billed reduces what we owe them without any money moving and
     * without the bill being touched — the bill's total is not editable, by
     * anybody, which is the whole control. Miss the term here and the control
     * account drifts from the subledger every time a delivery goes back.
     *
     * The same shape as OutstandingReceivables on the sell side, and for the
     * same reason: the moment a figure like this has three terms, three copies
     * of it start disagreeing.
     */
    public function totalPayable(): int
    {
        return $this->billed(null) - $this->paid(null) - $this->returned(null);
    }

    private function billed(?int $supplierId): int
    {
        return (int) SupplierBill::query()
            ->where('status', '!=', SupplierBill::STATUS_VOID)
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->sum('total_rupiah');
    }

    private function paid(?int $supplierId): int
    {
        return (int) SupplierPaymentEntry::query()
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->sum('amount_rupiah');
    }

    /**
     * Returns of goods the supplier had already billed for.
     *
     * `total_rupiah` on a return is deliberately only the billed part plus its
     * PPN — the part that reduces a real debt. What comes off Utang Belum
     * Ditagih instead was never a payable and must not be subtracted here.
     *
     * Drafts are excluded. A draft return is an intention, and letting one
     * reduce a payable would show a supplier as owed less on the strength of a
     * document nobody has posted.
     *
     * Mutation testing says removing that filter changes nothing, and today it
     * does not: a draft carries zeroes in every money column because only the
     * poster ever writes them. The filter states the rule rather than relying
     * on that — the day a draft shows a provisional figure on screen, this is
     * the line that stops the figure reaching the books.
     */
    private function returned(?int $supplierId): int
    {
        return (int) PurchaseReturn::query()
            ->posted()
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->sum('total_rupiah');
    }

    /**
     * Mark a bill paid once the ledger covers it.
     *
     * `status` follows the ledger rather than being set by whoever happened to
     * enter the last payment, so a part payment cannot be recorded as clearing
     * the bill.
     */
    private function settleIfCleared(?SupplierBill $bill): void
    {
        if ($bill === null) {
            return;
        }

        $bill->refresh();

        if ($bill->status === SupplierBill::STATUS_OPEN && $bill->amountOutstanding() <= 0) {
            $bill->forceFill(['status' => SupplierBill::STATUS_PAID])->save();
        }
    }
}
