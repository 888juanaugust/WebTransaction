<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Audit\AuditLogger;
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
    public function __construct(private readonly AuditLogger $audit) {}

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
        $billed = (int) SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', SupplierBill::STATUS_VOID)
            ->sum('total_rupiah');

        $paid = (int) SupplierPaymentEntry::query()
            ->where('supplier_id', $supplier->id)
            ->sum('amount_rupiah');

        return $billed - $paid;
    }

    /** Total accounts payable across every supplier. */
    public function totalPayable(): int
    {
        $billed = (int) SupplierBill::query()
            ->where('status', '!=', SupplierBill::STATUS_VOID)
            ->sum('total_rupiah');

        $paid = (int) SupplierPaymentEntry::query()->sum('amount_rupiah');

        return $billed - $paid;
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
