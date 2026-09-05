<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\BankAccounts;
use App\Domain\Money;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\PaymentEntry;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Money is an append-only ledger, same as stock.
 *
 * A payment row is never mutated. Corrections are reversing entries: same
 * amount, opposite sign, pointing back at what they undo.
 *
 * **An entry is money arriving; an allocation is what that money settles.**
 * They are two different facts and they are two different rows. One entry per
 * bank line is what makes the reconciliation desk possible, and a customer on
 * 30-day terms pays one figure at month end against four fakturs and part of
 * a fifth — so the link between them carries its own rupiah, in
 * `payment_allocations`, and is append-only for the same reason the entries
 * are.
 *
 * Nothing here will over-apply money. Allocating more than an invoice still
 * owes used to be silently accepted, leaving that invoice at a negative
 * outstanding while the rest of the customer's fakturs stood at full and the
 * remainder appeared in no queue at all. Both sides are now checked and the
 * refusal names the two numbers.
 */
class PaymentLedger
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentPoster $poster,
    ) {}

    /**
     * Post a payment finance keyed in by hand — a bank transfer matched
     * against the statement, or a cash payment at the counter. The only way
     * money enters this ledger.
     *
     * `$invoice` is the one-bill shorthand and allocates the whole amount to
     * it. For a transfer covering several fakturs use `$spread`, which takes
     * `[[Invoice, rupiah], …]`; the two are mutually exclusive, and money left
     * over from either is simply unallocated and waits in the queue.
     *
     * @param  list<array{0: Invoice, 1: int}>  $spread
     */
    public function recordManualPayment(
        Company $company,
        int $amountRupiah,
        User $actor,
        ?Invoice $invoice = null,
        ?string $catatan = null,
        ?DateTimeInterface $paidAt = null,
        ?BankAccount $rekening = null,
        array $spread = [],
    ): PaymentEntry {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh mengonfirmasi pembayaran.");
        }

        if ($invoice !== null && $spread !== []) {
            throw new LogicException('Sebutkan satu faktur atau rinciannya, bukan keduanya.');
        }

        if ($invoice !== null) {
            $spread = [[$invoice, $amountRupiah]];
        }

        // Resolved and STORED now, not looked up later: which rekening the
        // money hit is a fact about this payment, and the default moving next
        // year must not rewrite it.
        $rekening ??= app(BankAccounts::class)->default();

        return DB::transaction(function () use ($company, $amountRupiah, $actor, $invoice, $catatan, $paidAt, $rekening, $spread) {
            /*
             * Every faktur this receipt will touch, held before anything that
             * points at one is written.
             *
             * The order is not a style choice. Inserting a row with an
             * `invoice_id` takes a key-share lock on that faktur to hold the
             * foreign key still, and key-share is compatible with itself — so
             * two receipts against one faktur both get it, and then both ask
             * to upgrade to `FOR UPDATE`, and Postgres kills one for
             * deadlock. Two people banking against the same bill at the same
             * moment is not an exotic case; it is a Friday afternoon.
             *
             * Taking the exclusive lock first means the second receipt waits
             * where waiting is harmless, instead of dying where it is not.
             */
            $this->holdInvoices([
                ...($invoice !== null ? [$invoice] : []),
                ...array_map(fn (array $baris) => $baris[0], $spread),
            ]);

            $entry = PaymentEntry::create([
                'company_id' => $company->id,
                /*
                 * Still stamped for the one-bill case, because a great deal
                 * of the application reads it as "which faktur was this for"
                 * and it remains true there. It is **not** what the money is
                 * counted from any more — that is `payment_allocations`, and
                 * a payment spread over several fakturs leaves this null
                 * because no single answer exists.
                 */
                'invoice_id' => $invoice?->id,
                'amount_rupiah' => $amountRupiah,
                'kind' => PaymentEntry::KIND_PAYMENT,
                'actor_id' => $actor->id,
                'paid_at' => $paidAt ?? now(),
                'bank_account_id' => $rekening->id,
                'catatan' => $catatan,
            ]);

            foreach ($spread as [$bill, $jumlah]) {
                // Audited once, by the payment below. Two rows saying the
                // same thing about one keystroke is noise in the one log
                // that has to stay readable.
                $this->allocate($entry, $bill, (int) $jumlah, $actor, audit: false);
            }

            $this->poster->customerPaymentReceived($entry, $actor);

            $this->audit->log(
                action: 'payment_confirmed',
                subject: $entry,
                newValue: [
                    'amount_rupiah' => $amountRupiah,
                    'invoice_id' => $invoice?->id,
                    'faktur' => array_map(
                        fn (array $baris) => [$baris[0]->nomor, (int) $baris[1]],
                        $spread,
                    ),
                ],
                actor: $actor,
                alasan: $catatan,
            );

            return $entry;
        });
    }

    /**
     * Take the exclusive lock on every faktur this transaction will touch,
     * before anything that points at one is written.
     *
     * Two rules in one small method, and both were learned from a deadlock a
     * forked test produced rather than from reading the code:
     *
     * **Before the inserts.** A row carrying an `invoice_id` takes a
     * key-share lock on that faktur so the foreign key cannot move under it.
     * Key-share is compatible with itself, so two transactions both get it
     * and then both try to upgrade to `FOR UPDATE` — a cycle, and Postgres
     * ends one of them with SQLSTATE 40P01. Taking the exclusive lock first
     * turns that into an ordinary wait.
     *
     * **In a fixed order.** A receipt spread over several fakturs takes
     * several locks, and two receipts sharing two fakturs in opposite orders
     * deadlock on each other for the ordinary reason. Ascending id is an
     * order both agree on without having to know about each other.
     *
     * Region scope lifted: since the multi-warehouse split a customer's
     * fakturs can sit in another region's books, and a scoped lookup would
     * lock nothing and say nothing.
     *
     * @param  list<Invoice>  $invoices
     */
    private function holdInvoices(array $invoices): void
    {
        $ids = collect($invoices)
            ->filter()
            ->map(fn (Invoice $i) => (int) $i->getKey())
            ->unique()
            ->sort()
            ->values();

        foreach ($ids as $id) {
            Invoice::query()
                ->withoutGlobalScope('region')
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
        }
    }

    /**
     * Undo an entry by inserting its opposite. The original row is left
     * exactly as it was.
     */
    public function reverse(PaymentEntry $entry, User $actor, string $alasan): PaymentEntry
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh membalik pembayaran.");
        }

        return DB::transaction(function () use ($entry, $actor, $alasan) {
            $reversal = PaymentEntry::create([
                'company_id' => $entry->company_id,
                'invoice_id' => $entry->invoice_id,
                'order_id' => $entry->order_id,
                'amount_rupiah' => -$entry->amount_rupiah,
                'kind' => PaymentEntry::KIND_REVERSAL,
                'actor_id' => $actor->id,
                'reverses_entry_id' => $entry->id,
                'paid_at' => now(),
                // The undo leaves the same rekening the money entered.
                'bank_account_id' => $entry->bank_account_id,
                'catatan' => $alasan,
            ]);

            /*
             * The money is coming back out, so what it was said to settle
             * comes back out with it — one negative allocation per
             * application, hung off the reversal rather than the original,
             * so both entries stay net-zero against their own applications.
             *
             * Without this the invoices went on looking paid while the cash
             * had been returned: the reversal only ever reopened the single
             * invoice named on the entry, and a payment spread over four
             * fakturs would have left three of them settled by money that
             * was no longer there.
             */
            $terpakai = PaymentAllocation::query()
                ->where('payment_entry_id', $entry->id)
                ->where('amount_rupiah', '>', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw(1)
                    ->from('payment_allocations as pembatalan')
                    ->whereColumn('pembatalan.reverses_allocation_id', 'payment_allocations.id'))
                ->get();

            foreach ($terpakai as $alokasi) {
                PaymentAllocation::create([
                    'payment_entry_id' => $reversal->id,
                    'invoice_id' => $alokasi->invoice_id,
                    'amount_rupiah' => -$alokasi->amount_rupiah,
                    'actor_id' => $actor->id,
                    'reverses_allocation_id' => $alokasi->id,
                    'catatan' => $alasan,
                ]);

                $this->reopenIfNoLongerCovered($alokasi->invoice);
            }

            // The entry may also carry the legacy single-invoice stamp with
            // no allocation behind it; reopening is idempotent either way.
            $this->reopenIfNoLongerCovered($entry->invoice);

            /*
             * The reversal is its own row with a negative amount, so it posts
             * its own entry with both sides the other way round. The original
             * entry is not touched — same rule as the payment ledger itself.
             */
            $this->poster->customerPaymentReceived($reversal, $actor);

            $this->audit->log(
                action: 'payment_reversed',
                subject: $reversal,
                oldValue: ['payment_entry_id' => $entry->id, 'amount_rupiah' => $entry->amount_rupiah],
                newValue: ['amount_rupiah' => -$entry->amount_rupiah],
                actor: $actor,
                alasan: $alasan,
            );

            return $reversal;
        });
    }

    /**
     * Apply part (or all) of a payment to one invoice.
     *
     * Both sides are checked, and neither check is a formality. The entry
     * cannot give out more than arrived, and the invoice cannot take more
     * than it is owed — over-applying used to be accepted in silence, which
     * is how money ends up on no invoice, in no queue, and netting against
     * the customer's exposure as a negative balance.
     *
     * @param  bool  $audit  false only where the caller's own audit row already
     *                       records this exact application
     */
    public function allocate(
        PaymentEntry $entry,
        Invoice $invoice,
        int $amountRupiah,
        User $actor,
        ?string $catatan = null,
        bool $audit = true,
    ): PaymentAllocation {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh mencocokkan pembayaran.");
        }

        if ($amountRupiah <= 0) {
            throw new DomainException('Jumlah yang dicocokkan harus lebih dari nol.');
        }

        /*
         * One customer's money never settles another's bill. The screens
         * only offer the payer's own fakturs, but an id in a request is not
         * a promise, and this is the check that makes the boundary real.
         */
        if ((int) $invoice->company_id !== (int) $entry->company_id) {
            throw new DomainException(
                "Faktur {$invoice->nomor} milik pelanggan lain — pembayaran ini bukan pembayarannya."
            );
        }

        return DB::transaction(function () use ($entry, $invoice, $amountRupiah, $actor, $catatan, $audit) {
            /*
             * Both sides are held while their remainders are read, and both
             * are needed for the same reason: a figure read and then decided
             * on, with nothing keeping it still in between, is not a check.
             *
             * The entry stops two people banking one transfer against two
             * fakturs, each reading a remainder the other is about to spend.
             *
             * The invoice stops the mirror of that, which was live until
             * `MoneyRaceTest` forked processes at it: four separate transfers
             * applied to one Rp 10.000.000 faktur at the same instant each
             * saw the full remainder, and all four passed. Rp 40.000.000
             * against a Rp 10.000.000 bill — the over-application refusal
             * below is exactly what that was supposed to prevent, and it was
             * correct every time on the figures it was handed.
             *
             * **Invoice first, then entry**, and see `holdInvoices()` for why
             * the order is load-bearing rather than arbitrary.
             */
            $this->holdInvoices([$invoice]);

            $locked = PaymentEntry::query()->lockForUpdate()->findOrFail($entry->id);

            $sisaUang = $this->unallocated($locked);

            if ($amountRupiah > $sisaUang) {
                throw new DomainException(sprintf(
                    'Pembayaran ini hanya menyisakan %s yang belum dicocokkan, tidak cukup untuk %s.',
                    Money::format($sisaUang),
                    Money::format($amountRupiah),
                ));
            }

            $sisaTagihan = $invoice->fresh()->amountOutstanding();

            if ($amountRupiah > $sisaTagihan) {
                throw new DomainException(sprintf(
                    'Faktur %s hanya kurang %s, tidak bisa dicocokkan %s.',
                    $invoice->nomor,
                    Money::format($sisaTagihan),
                    Money::format($amountRupiah),
                ));
            }

            $alokasi = PaymentAllocation::create([
                'payment_entry_id' => $locked->id,
                'invoice_id' => $invoice->id,
                'amount_rupiah' => $amountRupiah,
                'actor_id' => $actor->id,
                'catatan' => $catatan,
            ]);

            $this->settleInvoiceIfCovered($invoice);

            if ($audit) {
                $this->audit->log(
                    action: 'payment_allocated',
                    subject: $alokasi,
                    newValue: [
                        'payment_entry_id' => $locked->id,
                        'faktur' => $invoice->nomor,
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
     * Take an application back — money misapplied to the wrong faktur.
     *
     * A negative row, never a delete. The money stays on the ledger and stays
     * the customer's; only what it was said to settle changes, and both
     * versions remain readable, which is the whole point when the customer
     * rings in March about an application made in January.
     */
    public function unallocate(PaymentAllocation $alokasi, User $actor, string $alasan): PaymentAllocation
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh mencocokkan pembayaran.");
        }

        if ($alokasi->amount_rupiah < 0) {
            throw new DomainException('Baris ini sudah pembatalan.');
        }

        if (PaymentAllocation::query()->where('reverses_allocation_id', $alokasi->id)->exists()) {
            throw new DomainException('Pencocokan ini sudah dibatalkan.');
        }

        return DB::transaction(function () use ($alokasi, $actor, $alasan) {
            $balik = PaymentAllocation::create([
                'payment_entry_id' => $alokasi->payment_entry_id,
                'invoice_id' => $alokasi->invoice_id,
                'amount_rupiah' => -$alokasi->amount_rupiah,
                'actor_id' => $actor->id,
                'reverses_allocation_id' => $alokasi->id,
                'catatan' => $alasan,
            ]);

            $this->reopenIfNoLongerCovered($alokasi->invoice);

            $this->audit->log(
                action: 'payment_unallocated',
                subject: $balik,
                oldValue: ['amount_rupiah' => $alokasi->amount_rupiah],
                newValue: ['amount_rupiah' => -$alokasi->amount_rupiah],
                actor: $actor,
                alasan: $alasan,
            );

            return $balik;
        });
    }

    /**
     * What arrived on this entry and has not been applied to anything.
     *
     * Net of reversals, since taking an application back is a negative row
     * rather than a deletion.
     */
    public function unallocated(PaymentEntry $entry): int
    {
        $dipakai = (int) PaymentAllocation::query()
            ->where('payment_entry_id', $entry->id)
            ->sum('amount_rupiah');

        return (int) $entry->amount_rupiah - $dipakai;
    }

    /**
     * Money in that is not fully accounted for — the collector's other queue.
     *
     * @return Collection<int, PaymentEntry>
     */
    public function unallocatedEntries(): Collection
    {
        return PaymentEntry::query()
            ->unmatched()
            ->with(['company', 'bankAccount', 'allocations.invoice', 'allocations.actor'])
            ->orderByDesc('paid_at')
            ->get();
    }

    /**
     * The customer's open fakturs, oldest first — what a receipt can be
     * spread across, and the order it is offered in.
     *
     * @return Collection<int, Invoice>
     */
    public function openInvoices(Company $company): Collection
    {
        return Invoice::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('status', Invoice::STATUS_OPEN)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $i) => $i->amountOutstanding() > 0)
            ->values();
    }

    /**
     * How a lump sum would land if nobody intervened: oldest bill first.
     *
     * A suggestion, not an act. It is offered as the editable default on the
     * receipt screen because oldest-first is what both sides assume when a
     * customer says "this is for my outstanding" — but which faktur a payment
     * settles can be the customer's own instruction, and the screen has to let
     * finance follow it.
     *
     * @return list<array{invoice: Invoice, amount: int}>
     */
    public function suggestSpread(Company $company, int $amountRupiah): array
    {
        $sisa = $amountRupiah;
        $rencana = [];

        $terbuka = Invoice::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('status', Invoice::STATUS_OPEN)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($terbuka as $invoice) {
            if ($sisa <= 0) {
                break;
            }

            $kurang = $invoice->amountOutstanding();

            if ($kurang <= 0) {
                continue;
            }

            $bagian = min($sisa, $kurang);
            $rencana[] = ['invoice' => $invoice, 'amount' => $bagian];
            $sisa -= $bagian;
        }

        return $rencana;
    }

    public function totalForOrder(Order $order): int
    {
        return (int) PaymentEntry::query()->where('order_id', $order->id)->sum('amount_rupiah');
    }

    /**
     * A faktur that no longer covers itself goes back to open.
     *
     * The mirror of settlement, and deliberately not the same thing said
     * backwards: this never touches the order. An order that reached `paid`
     * or `completed` on money since taken back is a business problem for a
     * person to look at, not something to unwind silently — the goods may
     * already have gone.
     */
    private function reopenIfNoLongerCovered(?Invoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        $invoice = $invoice->fresh();

        if ($invoice->status === Invoice::STATUS_PAID && $invoice->amountOutstanding() > 0) {
            $invoice->forceFill(['status' => Invoice::STATUS_OPEN])->save();
        }
    }

    /** @param  array<string, mixed>  $meta  carried onto the order's transition event */
    private function settleInvoiceIfCovered(?Invoice $invoice, array $meta = []): void
    {
        if ($invoice === null) {
            return;
        }

        $invoice = $invoice->fresh();

        if ($invoice->status === Invoice::STATUS_OPEN && $invoice->amountOutstanding() <= 0) {
            $invoice->forceFill(['status' => Invoice::STATUS_PAID])->save();

            $this->advanceOrder($invoice, $meta);
        }
    }

    /**
     * Settlement moves the order, because "finished" means paid.
     *
     * Two credit-sales positions land here. An order still awaiting payment
     * becomes `paid` — the prepay path. An order already shipped becomes
     * `completed` — the ordinary credit path, where the goods left months
     * before the money arrived and the last rupiah is what closes the file.
     * The transitions are logged like any other, with no actor: the money is
     * the actor.
     *
     * Resolved lazily rather than constructor-injected: the state machine
     * posts through this ledger's own collaborators, and a constructor cycle
     * is a worse smell than one container call in a private method.
     */
    /** @param  array<string, mixed>  $meta */
    private function advanceOrder(Invoice $invoice, array $meta = []): void
    {
        $order = $invoice->order;

        if ($order === null) {
            return;
        }

        $machine = app(OrderStateMachine::class);

        if ($order->status === OrderStatus::AwaitingPayment) {
            $machine->markPaid($order, meta: $meta + ['invoice_id' => $invoice->id, 'settled' => true]);

            return;
        }

        if ($order->status === OrderStatus::Shipped) {
            $machine->complete($order, null, 'Faktur lunas — transaksi selesai.');
        }
    }
}
