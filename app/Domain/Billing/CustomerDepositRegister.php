<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Money;
use App\Models\Company;
use App\Models\CustomerDeposit;
use App\Models\CustomerDepositMovement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Taking a deposit, spending it, and giving back what is left.
 *
 * The gap this fills is not that deposits were impossible — finance could
 * always key in a payment with no invoice on it. It is that doing so books the
 * money as **Cr Piutang Usaha**, so a customer who owes nothing and has paid
 * ten million shows a receivable of minus ten million. The neraca then
 * understates what customers owe and shows no liability at all, when we are in
 * fact holding cash we have delivered nothing for. Those are opposite sides of
 * the balance sheet, and netting one against the other hides both.
 *
 * ## No draft, no posting step
 *
 * Every other document here drafts first. This one does not, and the reason is
 * that the event already happened somewhere else: the money is in the bank. A
 * draft would be cash sitting in the account that the books have not heard
 * about, which is exactly the condition a deposit register exists to prevent.
 * A mistake is unwound by refunding it, not by editing it.
 *
 * ## Why an application creates a payment entry
 *
 * Four things need to know that a deposit came off an invoice: the invoice's
 * own settlement, the ageing report, the customer statement, and the portal.
 * All four already read `payment_entries`. Giving the application its own
 * parallel table would mean teaching all four about a second source and
 * getting one of them wrong.
 *
 * So it writes a payment entry, with a kind of its own — because although the
 * invoice sees an ordinary settlement, no cash moves that day and the journal
 * has to say so. See DocumentPoster::customerDepositApplied().
 */
class CustomerDepositRegister
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Money in, before anything is owed.
     *
     *   Dr Kas or Bank
     *     Cr Uang Muka Pelanggan
     */
    public function receive(
        Company $company,
        int $jumlahRupiah,
        PaidFrom $diterimaDi,
        DateTimeInterface $tanggal,
        User $actor,
        ?Order $order = null,
        ?string $referensi = null,
        ?string $catatan = null,
    ): CustomerDeposit {
        $this->assertMayHandle($actor);

        if ($jumlahRupiah <= 0) {
            throw new DomainException('Uang muka harus lebih dari nol.');
        }

        if ($order !== null && (int) $order->company_id !== (int) $company->id) {
            throw new DomainException("Pesanan {$order->nomor} bukan milik {$company->nama}.");
        }

        $date = Carbon::parse($tanggal)->startOfDay();

        if ($date->isFuture()) {
            throw new DomainException('Tanggal uang muka belum lewat.');
        }

        return DB::transaction(function () use (
            $company, $jumlahRupiah, $diterimaDi, $date, $actor, $order, $referensi, $catatan
        ) {
            $deposit = CustomerDeposit::create([
                'nomor' => $this->numbers->nextCustomerDepositNumber($date),
                'company_id' => $company->id,
                'order_id' => $order?->id,
                'tanggal' => $date,
                'jumlah_rupiah' => $jumlahRupiah,
                'diterima_di' => $diterimaDi,
                'referensi' => $referensi,
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            $this->poster->customerDepositReceived($deposit, $actor);

            $this->audit->log(
                action: 'customer_deposit_received',
                subject: $deposit,
                newValue: [
                    'nomor' => $deposit->nomor,
                    'pelanggan' => $company->nama,
                    'jumlah_rupiah' => $jumlahRupiah,
                    'diterima_di' => $diterimaDi->value,
                    'pesanan' => $order?->nomor,
                ],
                actor: $actor,
            );

            return $deposit->refresh();
        });
    }

    /**
     * Spend some of it against an invoice.
     *
     *   Dr Uang Muka Pelanggan
     *     Cr Piutang Usaha
     *
     * Bounded twice, and both bounds matter. More than the deposit holds would
     * spend money nobody paid; more than the invoice owes would overpay it and
     * push Piutang Usaha negative for that customer — the exact condition this
     * whole document exists to prevent.
     */
    public function apply(
        CustomerDeposit $deposit,
        Invoice $invoice,
        int $jumlahRupiah,
        User $actor,
        ?DateTimeInterface $tanggal = null,
    ): CustomerDepositMovement {
        $this->assertMayHandle($actor);

        if ($jumlahRupiah <= 0) {
            throw new DomainException('Nilai yang dipakai harus lebih dari nol.');
        }

        if ((int) $invoice->company_id !== (int) $deposit->company_id) {
            throw new DomainException(
                "Faktur {$invoice->nomor} bukan milik pelanggan yang menyetor uang muka ini."
            );
        }

        if ($invoice->status === Invoice::STATUS_VOID) {
            throw new DomainException("Faktur {$invoice->nomor} sudah dibatalkan.");
        }

        $date = Carbon::parse($tanggal ?? now())->startOfDay();

        return DB::transaction(function () use ($deposit, $invoice, $jumlahRupiah, $actor, $date) {
            /*
             * Locked, because two people applying the same deposit to two
             * invoices at once would each read a balance the other is about to
             * spend, and both would pass the check below. No single-threaded
             * test can reach that, so mutation testing reports the lock as
             * removable — it is not.
             */
            $locked = CustomerDeposit::query()->lockForUpdate()->findOrFail($deposit->id);

            $sisa = $locked->sisaRupiah();

            if ($jumlahRupiah > $sisa) {
                throw new DomainException(sprintf(
                    'Uang muka %s hanya tersisa %s, tidak cukup untuk %s.',
                    $locked->nomor,
                    Money::format($sisa),
                    Money::format($jumlahRupiah),
                ));
            }

            $owed = $invoice->fresh()->amountOutstanding();

            if ($jumlahRupiah > $owed) {
                throw new DomainException(sprintf(
                    'Faktur %s hanya kurang %s, tidak bisa dipakai %s.',
                    $invoice->nomor,
                    Money::format($owed),
                    Money::format($jumlahRupiah),
                ));
            }

            $payment = PaymentEntry::create([
                'company_id' => $locked->company_id,
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'amount_rupiah' => $jumlahRupiah,
                'kind' => PaymentEntry::KIND_DEPOSIT_APPLICATION,
                'actor_id' => $actor->id,
                'paid_at' => $date,
                'catatan' => "Uang muka {$locked->nomor}",
            ]);

            $movement = CustomerDepositMovement::create([
                'customer_deposit_id' => $locked->id,
                'jenis' => CustomerDepositMovement::JENIS_PAKAI,
                'jumlah_rupiah' => $jumlahRupiah,
                'invoice_id' => $invoice->id,
                'payment_entry_id' => $payment->id,
                'tanggal' => $date,
                'actor_id' => $actor->id,
            ]);

            $this->recacheAndClose($locked);
            $this->settleInvoiceIfCovered($invoice);

            $this->poster->customerDepositApplied($movement->fresh(['deposit', 'invoice']), $actor);

            $this->audit->log(
                action: 'customer_deposit_applied',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'faktur' => $invoice->nomor,
                    'jumlah_rupiah' => $jumlahRupiah,
                    'sisa_rupiah' => $locked->refresh()->sisaRupiah(),
                ],
                actor: $actor,
            );

            return $movement;
        });
    }

    /**
     * Give back what is left.
     *
     *   Dr Uang Muka Pelanggan
     *     Cr Kas or Bank
     *
     * Not a reversal of the original entry. The money genuinely came in and
     * genuinely went out again, and the bank statement will show both — cancel
     * the receipt instead and the reconciliation is two movements short with
     * nothing to explain either.
     */
    public function refund(
        CustomerDeposit $deposit,
        int $jumlahRupiah,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
    ): CustomerDepositMovement {
        $this->assertMayHandle($actor);

        if ($jumlahRupiah <= 0) {
            throw new DomainException('Nilai yang dikembalikan harus lebih dari nol.');
        }

        if (trim($alasan) === '') {
            throw new DomainException('Pengembalian uang muka harus menyebutkan alasannya.');
        }

        $date = Carbon::parse($tanggal ?? now())->startOfDay();

        return DB::transaction(function () use ($deposit, $jumlahRupiah, $actor, $alasan, $date) {
            $locked = CustomerDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            $sisa = $locked->sisaRupiah();

            if ($jumlahRupiah > $sisa) {
                throw new DomainException(sprintf(
                    'Uang muka %s hanya tersisa %s, tidak bisa dikembalikan %s.',
                    $locked->nomor,
                    Money::format($sisa),
                    Money::format($jumlahRupiah),
                ));
            }

            $movement = CustomerDepositMovement::create([
                'customer_deposit_id' => $locked->id,
                'jenis' => CustomerDepositMovement::JENIS_KEMBALI,
                'jumlah_rupiah' => $jumlahRupiah,
                'tanggal' => $date,
                'catatan' => trim($alasan),
                'actor_id' => $actor->id,
            ]);

            $this->recacheAndClose($locked);

            $this->poster->customerDepositRefunded($movement->fresh('deposit'), $actor);

            $this->audit->log(
                action: 'customer_deposit_refunded',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'jumlah_rupiah' => $jumlahRupiah,
                    'sisa_rupiah' => $locked->refresh()->sisaRupiah(),
                ],
                actor: $actor,
                alasan: trim($alasan),
            );

            return $movement;
        });
    }

    /**
     * Rewrite the cached totals from the movements that justify them.
     *
     * Summed rather than incremented. An increment is right until the day a
     * movement is inserted by something else, and then the cached figure is
     * wrong with no way to tell — the same rule the stock ledger follows.
     */
    private function recacheAndClose(CustomerDeposit $deposit): void
    {
        $sums = CustomerDepositMovement::query()
            ->where('customer_deposit_id', $deposit->id)
            ->selectRaw('jenis, SUM(jumlah_rupiah) AS total')
            ->groupBy('jenis')
            ->pluck('total', 'jenis');

        $terpakai = (int) ($sums[CustomerDepositMovement::JENIS_PAKAI] ?? 0);
        $dikembalikan = (int) ($sums[CustomerDepositMovement::JENIS_KEMBALI] ?? 0);

        $deposit->forceFill([
            'terpakai_rupiah' => $terpakai,
            'dikembalikan_rupiah' => $dikembalikan,
            'status' => (int) $deposit->jumlah_rupiah - $terpakai - $dikembalikan <= 0
                ? CustomerDeposit::STATUS_CLOSED
                : CustomerDeposit::STATUS_HELD,
        ])->save();
    }

    private function settleInvoiceIfCovered(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();

        if ($invoice->status === Invoice::STATUS_OPEN && $invoice->amountOutstanding() <= 0) {
            $invoice->forceFill(['status' => Invoice::STATUS_PAID])->save();
        }
    }

    /**
     * Taking a deposit is confirming money arrived, so it sits behind the same
     * permission as confirming a payment — and behind the same separation.
     * Sales may not do it: whoever agreed the price must not also be the one
     * who says the money came in.
     */
    private function assertMayHandle(User $actor): void
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Anda tidak berhak mencatat uang muka pelanggan.');
        }
    }
}
