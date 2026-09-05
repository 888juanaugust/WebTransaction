<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\BankAccounts;
use App\Domain\Money;
use App\Models\BankAccount;
use App\Models\Giro;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Money out, append-only — the mirror of PaymentLedger.
 *
 * A payment row is never mutated. Corrections are reversing entries: same
 * amount, opposite sign, pointing back at what they undo. The bill's total is
 * never touched by any of it, which is the whole control: whoever pays cannot
 * move the amount owed.
 *
 * **An entry is money leaving; an allocation is what it discharges** — the
 * same separation as the receivable side, and needed more here, because
 * paying a supplier once a month against everything they have sent is the
 * ordinary shape of a trade account rather than the exception. One entry per
 * bank line is what lets the reconciliation desk tick it; which debts that
 * line clears is a second question with its own rows in
 * `supplier_payment_allocations`.
 *
 * Nothing here over-pays a bill. Recording a Rp 42.000.000 transfer against a
 * Rp 12.000.000 bill used to mark it paid and leave it at minus thirty
 * million, while the Rp 30.000.000 bill the same transfer covered stayed
 * fully open and went on ageing in Umur hutang. The supplier's total came out
 * right, which is exactly what kept it quiet.
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
    /**
     * @param  list<array{0: SupplierBill, 1: int}>  $spread  one transfer,
     *                                                        several tagihan
     */
    public function recordPayment(
        Supplier $supplier,
        int $amountRupiah,
        User $actor,
        ?SupplierBill $bill = null,
        ?string $referensi = null,
        ?string $catatan = null,
        ?DateTimeInterface $paidAt = null,
        ?BankAccount $rekening = null,
        array $spread = [],
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

        if ($bill !== null && $spread !== []) {
            throw new LogicException('Sebutkan satu tagihan atau rinciannya, bukan keduanya.');
        }

        if ($bill !== null) {
            $spread = [[$bill, $amountRupiah]];
        }

        // Stored at record time — see PaymentLedger for why the default
        // moving later must never rewrite where this money actually left.
        $rekening ??= app(BankAccounts::class)->default();

        return DB::transaction(function () use (
            $supplier, $amountRupiah, $actor, $bill, $referensi, $catatan, $paidAt, $rekening, $spread
        ) {
            // Every tagihan this transfer will touch, held before anything
            // pointing at one is written — see holdBills().
            $this->holdBills([
                ...($bill !== null ? [$bill] : []),
                ...array_map(fn (array $baris) => $baris[0], $spread),
            ]);

            $entry = SupplierPaymentEntry::create([
                'supplier_id' => $supplier->id,
                /*
                 * Still stamped for the one-bill case — plenty of the
                 * application reads it as "which tagihan was this for", and
                 * there it remains true. It is not what the money is counted
                 * from; that is `supplier_payment_allocations`, and a
                 * transfer spread over several bills leaves this null because
                 * no single answer exists.
                 */
                'supplier_bill_id' => $bill?->id,
                'amount_rupiah' => $amountRupiah,
                'kind' => SupplierPaymentEntry::KIND_PAYMENT,
                'referensi' => $referensi,
                'actor_id' => $actor->id,
                'paid_at' => $paidAt ?? now(),
                'bank_account_id' => $rekening->id,
                'catatan' => $catatan,
            ]);

            foreach ($spread as [$tagihan, $jumlah]) {
                // Audited once, by the payment below.
                $this->allocate($entry, $tagihan, (int) $jumlah, $actor, audit: false);
            }

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
                    'tagihan' => array_map(
                        fn (array $baris) => [$baris[0]->nomor, (int) $baris[1]],
                        $spread,
                    ),
                ],
                actor: $actor,
            );

            return $entry;
        });
    }

    /**
     * Take the exclusive lock on every tagihan this transaction will touch,
     * before anything pointing at one is written.
     *
     * The mirror of `PaymentLedger::holdInvoices`, and the same two rules for
     * the same reasons: a row carrying a `supplier_bill_id` takes a key-share
     * lock on that bill, key-share is compatible with itself, and two
     * transfers both holding it and then both asking to upgrade is a deadlock
     * rather than a wait. Ascending id so two transfers sharing two bills
     * agree on an order without knowing about each other.
     *
     * @param  list<SupplierBill>  $bills
     */
    private function holdBills(array $bills): void
    {
        $ids = collect($bills)
            ->filter()
            ->map(fn (SupplierBill $b) => (int) $b->getKey())
            ->unique()
            ->sort()
            ->values();

        foreach ($ids as $id) {
            SupplierBill::query()
                ->withoutGlobalScope('region')
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
        }
    }

    /**
     * Apply part (or all) of a payment to one bill.
     *
     * Both sides checked. Over-paying a bill used to be accepted in silence,
     * which left it at a negative outstanding while the other bills the same
     * transfer covered went on ageing as though nothing had been paid.
     *
     * @param  bool  $audit  false only where the caller's own audit row already
     *                       records this exact application
     */
    public function allocate(
        SupplierPaymentEntry $entry,
        SupplierBill $bill,
        int $amountRupiah,
        User $actor,
        ?string $catatan = null,
        bool $audit = true,
    ): SupplierPaymentAllocation {
        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Anda tidak berhak mencocokkan pembayaran ke pemasok.');
        }

        if ($amountRupiah <= 0) {
            throw new DomainException('Jumlah yang dicocokkan harus lebih dari nol.');
        }

        // One supplier's money never discharges another's bill. The screens
        // only offer the payee's own tagihan, but an id in a request is not a
        // promise.
        if ((int) $bill->supplier_id !== (int) $entry->supplier_id) {
            throw new DomainException(
                "Tagihan {$bill->nomor} milik pemasok lain — pembayaran ini bukan untuk mereka."
            );
        }

        if ($bill->status === SupplierBill::STATUS_VOID) {
            throw new DomainException("Tagihan {$bill->nomor} sudah dibatalkan.");
        }

        return DB::transaction(function () use ($entry, $bill, $amountRupiah, $actor, $catatan, $audit) {
            /*
             * Locked while we read the remainder. Two people applying the same
             * transfer to two bills at once would each read a remainder the
             * other is about to spend. No single-threaded test reaches it; the
             * lock is not dead code.
             */
            /*
             * The bill first, then the entry — see `holdBills()`, and the
             * mirror reasoning in PaymentLedger. Four transfers applied to
             * one Rp 10.000.000 faktur at the same instant each read the full
             * remainder and all four passed; paying a supplier twice for one
             * bill is that defect facing the other way, and it costs cash out
             * rather than a wrong number on a report.
             */
            $this->holdBills([$bill]);

            $locked = SupplierPaymentEntry::query()->lockForUpdate()->findOrFail($entry->id);

            $sisaUang = $this->unallocated($locked);

            if ($amountRupiah > $sisaUang) {
                throw new DomainException(sprintf(
                    'Pembayaran ini hanya menyisakan %s yang belum dicocokkan, tidak cukup untuk %s.',
                    Money::format($sisaUang),
                    Money::format($amountRupiah),
                ));
            }

            $sisaTagihan = $bill->fresh()->amountOutstanding();

            if ($amountRupiah > $sisaTagihan) {
                throw new DomainException(sprintf(
                    'Tagihan %s hanya kurang %s, tidak bisa dicocokkan %s.',
                    $bill->nomor,
                    Money::format($sisaTagihan),
                    Money::format($amountRupiah),
                ));
            }

            $alokasi = SupplierPaymentAllocation::create([
                'supplier_payment_entry_id' => $locked->id,
                'supplier_bill_id' => $bill->id,
                'amount_rupiah' => $amountRupiah,
                'actor_id' => $actor->id,
                'catatan' => $catatan,
            ]);

            $this->settleIfCleared($bill);

            if ($audit) {
                $this->audit->log(
                    action: 'supplier_payment_allocated',
                    subject: $alokasi,
                    newValue: [
                        'supplier_payment_entry_id' => $locked->id,
                        'tagihan' => $bill->nomor,
                        'amount_rupiah' => $amountRupiah,
                    ],
                    actor: $actor,
                    alasan: $catatan,
                );
            }

            return $alokasi;
        });
    }

    /**
     * Take an application back — money applied to the wrong tagihan.
     *
     * A negative row, never a delete. The money stays out of the account and
     * stays the supplier's; only what it was said to discharge changes, and
     * both versions stay readable for the day the supplier queries a
     * statement.
     */
    public function unallocate(
        SupplierPaymentAllocation $alokasi,
        User $actor,
        string $alasan,
    ): SupplierPaymentAllocation {
        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Anda tidak berhak mencocokkan pembayaran ke pemasok.');
        }

        if ($alokasi->amount_rupiah < 0) {
            throw new DomainException('Baris ini sudah pembatalan.');
        }

        if (SupplierPaymentAllocation::query()->where('reverses_allocation_id', $alokasi->id)->exists()) {
            throw new DomainException('Pencocokan ini sudah dibatalkan.');
        }

        return DB::transaction(function () use ($alokasi, $actor, $alasan) {
            $balik = SupplierPaymentAllocation::create([
                'supplier_payment_entry_id' => $alokasi->supplier_payment_entry_id,
                'supplier_bill_id' => $alokasi->supplier_bill_id,
                'amount_rupiah' => -$alokasi->amount_rupiah,
                'actor_id' => $actor->id,
                'reverses_allocation_id' => $alokasi->id,
                'catatan' => $alasan,
            ]);

            $this->reopenIfNoLongerCleared($alokasi->bill);

            $this->audit->log(
                action: 'supplier_payment_unallocated',
                subject: $balik,
                oldValue: ['amount_rupiah' => $alokasi->amount_rupiah],
                newValue: ['amount_rupiah' => -$alokasi->amount_rupiah],
                actor: $actor,
                alasan: $alasan,
            );

            return $balik;
        });
    }

    /** What left the account on this entry and discharges nothing yet. */
    public function unallocated(SupplierPaymentEntry $entry): int
    {
        $dipakai = (int) SupplierPaymentAllocation::query()
            ->where('supplier_payment_entry_id', $entry->id)
            ->sum('amount_rupiah');

        return (int) $entry->amount_rupiah - $dipakai;
    }

    /**
     * How a lump payment would land if nobody intervened: oldest bill first.
     *
     * An offer, not an act. Which bill a payment clears can be what the
     * supplier's own statement says, and the person reconciling with them has
     * to be able to follow it.
     *
     * @return list<array{bill: SupplierBill, amount: int}>
     */
    public function suggestSpread(Supplier $supplier, int $amountRupiah): array
    {
        $sisa = $amountRupiah;
        $rencana = [];

        foreach ($this->openBills($supplier) as $bill) {
            if ($sisa <= 0) {
                break;
            }

            $bagian = min($sisa, $bill->amountOutstanding());
            $rencana[] = ['bill' => $bill, 'amount' => $bagian];
            $sisa -= $bagian;
        }

        return $rencana;
    }

    /**
     * The supplier's bills still owed, oldest due date first.
     *
     * @return Collection<int, SupplierBill>
     */
    public function openBills(Supplier $supplier): Collection
    {
        return SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierBill::STATUS_OPEN)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (SupplierBill $b) => $b->amountOutstanding() > 0)
            ->values();
    }

    /**
     * Money out that is not fully accounted for.
     *
     * @return Collection<int, SupplierPaymentEntry>
     */
    public function unallocatedEntries(): Collection
    {
        return SupplierPaymentEntry::query()
            ->unmatched()
            ->with(['supplier', 'bankAccount', 'allocations.bill', 'allocations.actor'])
            ->orderByDesc('paid_at')
            ->get();
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
                'bank_account_id' => $entry->bank_account_id,
                'catatan' => $alasan,
            ]);

            /*
             * The money is coming back, so what it was said to discharge comes
             * back with it — one negative allocation per application, hung off
             * the reversal so both entries stay net-zero against their own.
             *
             * Without this the bills went on looking paid while the transfer
             * had been recalled: the reversal only re-evaluated the single
             * bill named on the entry, and a payment spread over four would
             * have left three of them settled by money that had gone back.
             */
            $terpakai = SupplierPaymentAllocation::query()
                ->where('supplier_payment_entry_id', $entry->id)
                ->where('amount_rupiah', '>', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw(1)
                    ->from('supplier_payment_allocations as pembatalan')
                    ->whereColumn('pembatalan.reverses_allocation_id', 'supplier_payment_allocations.id'))
                ->get();

            foreach ($terpakai as $alokasi) {
                SupplierPaymentAllocation::create([
                    'supplier_payment_entry_id' => $reversal->id,
                    'supplier_bill_id' => $alokasi->supplier_bill_id,
                    'amount_rupiah' => -$alokasi->amount_rupiah,
                    'actor_id' => $actor->id,
                    'reverses_allocation_id' => $alokasi->id,
                    'catatan' => $alasan,
                ]);

                $this->reopenIfNoLongerCleared($alokasi->bill);
            }

            /*
             * A reversal can take a settled bill back to open — the money was
             * never really there. Re-evaluating rather than assuming keeps the
             * status a function of the ledger instead of of the last action.
             * Still done for the entry's own stamp, which may carry no
             * allocation behind it; re-evaluating twice is harmless.
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

    /**
     * What we still owe a supplier across every open bill.
     *
     * Not net of giro: a giro we have issued is still money we owe, committed
     * to a date rather than paid. The mirror of how a customer's giro does not
     * free their credit — see OutstandingReceivables.
     */
    public function outstandingFor(Supplier $supplier): int
    {
        return $this->billed($supplier->id)
            - $this->paid($supplier->id)
            - $this->returned($supplier->id)
            - $this->credited($supplier->id);
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
        return $this->billed(null)
            - $this->paid(null)
            - $this->returned(null)
            - $this->credited(null)
            - $this->giroIssued();
    }

    /**
     * Face value of our own giro that suppliers hold and have not cashed.
     *
     * The books moved that balance into Utang Giro when the paper was handed
     * over, so the Utang Usaha control account has to subtract it — while
     * `outstandingFor()` above deliberately does not, because we still owe it.
     */
    public function giroIssued(): int
    {
        return (int) Giro::query()->open()->keluar()->sum('nilai_rupiah');
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
    /**
     * Credit notes the supplier issued: a price corrected, no goods moved.
     *
     * A fourth term, and it has to be here for the same reason the third one
     * does — the bill's total is not editable by anybody, so the only way a
     * corrected price reaches the payable is as a separate document. Miss it
     * and Utang Usaha drifts from the subledger every time a supplier admits
     * they overcharged.
     *
     * Drafts excluded: a note somebody typed while querying it with the
     * supplier is not yet an agreement, and letting it reduce a payable would
     * show a debt as settled on the strength of a phone call.
     */
    private function credited(?int $supplierId): int
    {
        return (int) SupplierCreditNote::query()
            ->posted()
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->sum('total_rupiah');
    }

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
    /**
     * A bill no longer covered goes back to open.
     *
     * The mirror of settlement. Nothing else unwinds: goods received against
     * a bill stay received, and a payment taken back is a matter for a person
     * to look at rather than something to reverse silently through the
     * purchasing chain.
     */
    private function reopenIfNoLongerCleared(?SupplierBill $bill): void
    {
        if ($bill === null) {
            return;
        }

        $bill->refresh();

        if ($bill->status === SupplierBill::STATUS_PAID && $bill->amountOutstanding() > 0) {
            $bill->forceFill(['status' => SupplierBill::STATUS_OPEN])->save();
        }
    }

    /**
     * A bill with nothing left owing is closed, however it got there.
     *
     * Public because a credit note can clear one just as a payment can, and
     * before `amountCredited()` existed a fully-credited bill stayed `open` at
     * its full amount — ageing in Umur hutang and standing in the payment run
     * for money that was no longer owed.
     */
    public function settleIfCleared(?SupplierBill $bill): void
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
