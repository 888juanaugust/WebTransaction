<?php

declare(strict_types=1);

namespace App\Domain\Giro;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\Company;
use App\Models\Giro;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The drawer of postdated cheques, and what happens to each one.
 *
 * A giro is registered when the paper changes hands, and from that moment the
 * books show the balance sitting in a giro account rather than an ordinary
 * one. Nothing has been paid. It ends one of three ways, and only one of them
 * involves money:
 *
 *   **cair**       — it cleared. The instrument is unwound and an ordinary
 *                    payment is recorded, through the ledger that already
 *                    knows how to settle an invoice.
 *   **ditolak**    — it bounced. The instrument is unwound and the debt is
 *                    back exactly where it was, along with the risk.
 *   **dibatalkan** — handed back uncashed. Same unwinding, no blame.
 *
 * Those three share one journal entry, written once, because they are the same
 * event: the paper stops being a claim. What differs is only what happens
 * next.
 *
 * Nothing here is reversible after the fact. A giro wrongly marked cleared is
 * corrected by reversing the payment it created, which the payment ledger
 * already does — this class does not grow an undo of its own.
 */
class GiroRegister
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentPoster $poster,
        private readonly PaymentLedger $payments,
        private readonly SupplierLedger $suppliers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A customer hands over a giro.
     *
     * Dr Piutang Giro / Cr Piutang Usaha. The invoice stays open, because it
     * has not been paid — and the customer's credit limit stays spent, because
     * a giro can bounce and freeing credit on one is how a customer with a
     * history of bouncing them keeps ordering.
     */
    public function receive(
        Company $company,
        int $nilaiRupiah,
        string $bankPenerbit,
        string $nomorWarkat,
        DateTimeInterface $jatuhTempo,
        User $actor,
        ?Invoice $invoice = null,
        ?DateTimeInterface $diterima = null,
        ?string $catatan = null,
    ): Giro {
        if ($invoice !== null && $invoice->company_id !== $company->id) {
            throw new DomainException('Faktur itu milik pelanggan lain.');
        }

        return $this->register(
            arah: GiroDirection::Masuk,
            company: $company,
            supplier: null,
            invoice: $invoice,
            bill: null,
            nilaiRupiah: $nilaiRupiah,
            bankPenerbit: $bankPenerbit,
            nomorWarkat: $nomorWarkat,
            jatuhTempo: $jatuhTempo,
            diterima: $diterima,
            actor: $actor,
            catatan: $catatan,
        );
    }

    /**
     * We hand a supplier a giro.
     *
     * Dr Utang Usaha / Cr Utang Giro. The bill is not paid — the money is
     * still in the account — but it is committed to a date, which is a
     * different thing from being free.
     */
    public function issue(
        Supplier $supplier,
        int $nilaiRupiah,
        string $bankPenerbit,
        string $nomorWarkat,
        DateTimeInterface $jatuhTempo,
        User $actor,
        ?SupplierBill $bill = null,
        ?DateTimeInterface $diserahkan = null,
        ?string $catatan = null,
    ): Giro {
        if ($bill !== null && $bill->supplier_id !== $supplier->id) {
            throw new DomainException('Tagihan itu milik pemasok lain.');
        }

        return $this->register(
            arah: GiroDirection::Keluar,
            company: null,
            supplier: $supplier,
            invoice: null,
            bill: $bill,
            nilaiRupiah: $nilaiRupiah,
            bankPenerbit: $bankPenerbit,
            nomorWarkat: $nomorWarkat,
            jatuhTempo: $jatuhTempo,
            diterima: $diserahkan,
            actor: $actor,
            catatan: $catatan,
        );
    }

    /**
     * Record that a giro has been taken to the bank.
     *
     * No money and no journal — the bank has it and has not said anything yet.
     * It is here because "banked and waiting" and "still in the drawer" are
     * both work, and they are different work: one is chasing the bank and the
     * other is chasing yourself.
     *
     * Incoming giros only. A giro we issued is banked by the supplier, and we
     * find out it happened when the money leaves.
     */
    public function markDeposited(Giro $giro, User $actor, ?DateTimeInterface $tanggal = null): Giro
    {
        $this->assertMayHandle($actor);

        if ($giro->arah !== GiroDirection::Masuk) {
            throw new DomainException('Giro keluar disetorkan oleh pemasok, bukan oleh kita.');
        }

        if (! $giro->isOpen()) {
            throw new DomainException("Giro {$giro->nomor} sudah {$giro->status->label()}.");
        }

        $tanggal = Carbon::parse($tanggal ?? now());

        if ($tanggal->lessThan($giro->tanggal_jatuh_tempo)) {
            throw new DomainException(sprintf(
                'Giro %s baru bisa disetor pada %s.',
                $giro->nomor_warkat,
                $giro->tanggal_jatuh_tempo->format('d/m/Y'),
            ));
        }

        $giro->forceFill(['tanggal_setor' => $tanggal])->save();

        $this->audit->log(
            action: 'giro_deposited',
            subject: $giro,
            newValue: ['tanggal_setor' => $tanggal->toDateString()],
            actor: $actor,
        );

        return $giro->refresh();
    }

    /**
     * The giro cleared. This is the only ending where money moves.
     *
     * Two entries, deliberately. The first unwinds the instrument — the paper
     * has stopped being a claim — and the second is an ordinary payment.
     * Posting Dr Bank / Cr Piutang Giro in one entry would be shorter and
     * would bypass the payment ledger, which is what settles the invoice,
     * feeds the ageing report, queues an unmatched receipt for allocation and
     * knows how to be reversed. None of that is worth reimplementing to save
     * a journal line, and "the instrument was released, then the money
     * arrived" is what actually happened.
     */
    public function clear(Giro $giro, User $actor, ?DateTimeInterface $tanggal = null): Giro
    {
        $this->assertMayHandle($actor);
        $this->assertOpen($giro);

        return DB::transaction(function () use ($giro, $actor, $tanggal) {
            $locked = Giro::query()->lockForUpdate()->findOrFail($giro->id);

            $this->assertOpen($locked);

            $cair = Carbon::parse($tanggal ?? now());

            $locked->forceFill([
                'status' => GiroStatus::Cair,
                'tanggal_selesai' => $cair,
                'resolved_by' => $actor->id,
            ])->save();

            $this->poster->giroReleased(
                $locked->refresh(),
                $actor,
                "Giro {$locked->nomor_warkat} cair",
            );

            $this->recordPaymentFor($locked, $actor, $cair);

            $this->audit->log(
                action: 'giro_cleared',
                subject: $locked,
                newValue: [
                    'nomor_warkat' => $locked->nomor_warkat,
                    'nilai_rupiah' => (int) $locked->nilai_rupiah,
                    'tanggal_cair' => $cair->toDateString(),
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    /**
     * The giro bounced.
     *
     * The debt comes straight back to where it was, which is the honest
     * outcome and the reason none of this was recorded as a payment in the
     * first place: there is no invoice to reopen and no payment to reverse,
     * because neither ever happened.
     *
     * The reason is mandatory. "Saldo tidak cukup" and "rekening ditutup" are
     * different futures for the relationship, and six months later nobody
     * remembers which it was.
     */
    public function bounce(
        Giro $giro,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
    ): Giro {
        if (trim($alasan) === '') {
            throw new DomainException('Giro tolak harus menyebutkan alasan dari bank.');
        }

        return $this->release($giro, $actor, GiroStatus::Ditolak, $alasan, $tanggal);
    }

    /**
     * Handed back without ever being banked.
     *
     * The customer paid cash and took their paper home, or it was written
     * wrong and will be reissued. Not a failure, and not a payment.
     */
    public function cancel(
        Giro $giro,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
    ): Giro {
        if (trim($alasan) === '') {
            throw new DomainException('Pembatalan giro harus menyebutkan alasannya.');
        }

        return $this->release($giro, $actor, GiroStatus::Dibatalkan, $alasan, $tanggal);
    }

    /** Total face value of giro outstanding in one direction. */
    public function outstanding(GiroDirection $arah): int
    {
        return (int) Giro::query()->open()->where('arah', $arah->value)->sum('nilai_rupiah');
    }

    /** What one customer has handed over and we have not banked into money. */
    public function outstandingForCompany(Company $company): int
    {
        return (int) Giro::query()->open()->masuk()
            ->where('company_id', $company->id)
            ->sum('nilai_rupiah');
    }

    public function outstandingForSupplier(Supplier $supplier): int
    {
        return (int) Giro::query()->open()->keluar()
            ->where('supplier_id', $supplier->id)
            ->sum('nilai_rupiah');
    }

    /**
     * The shared ending: the instrument stops being a claim.
     *
     * `cair` runs through here too, in effect — it does this and then records
     * a payment on top.
     */
    private function release(
        Giro $giro,
        User $actor,
        GiroStatus $status,
        string $alasan,
        ?DateTimeInterface $tanggal,
    ): Giro {
        $this->assertMayHandle($actor);
        $this->assertOpen($giro);

        return DB::transaction(function () use ($giro, $actor, $status, $alasan, $tanggal) {
            $locked = Giro::query()->lockForUpdate()->findOrFail($giro->id);

            $this->assertOpen($locked);

            $locked->forceFill([
                'status' => $status,
                'tanggal_selesai' => Carbon::parse($tanggal ?? now()),
                'alasan_selesai' => $alasan,
                'resolved_by' => $actor->id,
            ])->save();

            $this->poster->giroReleased(
                $locked->refresh(),
                $actor,
                "Giro {$locked->nomor_warkat} {$status->label()}: {$alasan}",
            );

            $this->audit->log(
                action: 'giro_'.$status->value,
                subject: $locked,
                newValue: [
                    'nomor_warkat' => $locked->nomor_warkat,
                    'nilai_rupiah' => (int) $locked->nilai_rupiah,
                    'status' => $status->value,
                ],
                actor: $actor,
                alasan: $alasan,
            );

            return $locked->refresh();
        });
    }

    /**
     * Turn a cleared giro into a payment on the ledger it belongs to.
     *
     * With no document behind it the payment lands unallocated, which is not a
     * gap: one giro against three months of invoices is the ordinary case, and
     * the unmatched-payments queue already exists to sort exactly that out.
     */
    private function recordPaymentFor(Giro $giro, User $actor, Carbon $cair): void
    {
        if ($giro->arah === GiroDirection::Masuk) {
            $entry = $this->payments->recordManualPayment(
                company: $giro->company,
                amountRupiah: (int) $giro->nilai_rupiah,
                actor: $actor,
                invoice: $giro->invoice,
                catatan: "Giro {$giro->bank_penerbit} {$giro->nomor_warkat} cair",
                paidAt: $cair,
            );

            $giro->forceFill(['payment_entry_id' => $entry->id])->save();

            return;
        }

        $entry = $this->suppliers->recordPayment(
            supplier: $giro->supplier,
            amountRupiah: (int) $giro->nilai_rupiah,
            actor: $actor,
            bill: $giro->supplierBill,
            referensi: "{$giro->bank_penerbit} {$giro->nomor_warkat}",
            catatan: 'Giro dicairkan pemasok',
            paidAt: $cair,
        );

        $giro->forceFill(['supplier_payment_entry_id' => $entry->id])->save();
    }

    /** @param  Invoice|SupplierBill|null  $document */
    private function register(
        GiroDirection $arah,
        ?Company $company,
        ?Supplier $supplier,
        ?Invoice $invoice,
        ?SupplierBill $bill,
        int $nilaiRupiah,
        string $bankPenerbit,
        string $nomorWarkat,
        DateTimeInterface $jatuhTempo,
        ?DateTimeInterface $diterima,
        User $actor,
        ?string $catatan,
    ): Giro {
        $this->assertMayHandle($actor);

        if ($nilaiRupiah <= 0) {
            throw new DomainException('Nilai giro harus lebih dari nol.');
        }

        $bank = $this->normaliseBank($bankPenerbit);
        $warkat = trim($nomorWarkat);

        if ($bank === '' || $warkat === '') {
            throw new DomainException('Bank penerbit dan nomor warkat wajib diisi.');
        }

        $diterima = Carbon::parse($diterima ?? now());
        $jatuhTempo = Carbon::parse($jatuhTempo);

        /*
         * A giro dated before the day it was handed over is not postdated, it
         * is a mistake — almost always a year typed wrong, which would put the
         * whole thing straight into the overdue queue and stay there.
         */
        if ($jatuhTempo->lessThan($diterima->copy()->startOfDay())) {
            throw new DomainException(
                'Tanggal jatuh tempo giro tidak boleh sebelum tanggal diterima.'
            );
        }

        $this->assertWarkatIsNew($bank, $warkat);

        return DB::transaction(function () use (
            $arah, $company, $supplier, $invoice, $bill, $nilaiRupiah,
            $bank, $warkat, $diterima, $jatuhTempo, $actor, $catatan
        ) {
            $giro = Giro::create([
                'nomor' => $this->numbers->nextGiroNumber($diterima),
                'arah' => $arah,
                'company_id' => $company?->id,
                'supplier_id' => $supplier?->id,
                'invoice_id' => $invoice?->id,
                'supplier_bill_id' => $bill?->id,
                'bank_penerbit' => $bank,
                'nomor_warkat' => $warkat,
                'nilai_rupiah' => $nilaiRupiah,
                'tanggal_terima' => $diterima,
                'tanggal_jatuh_tempo' => $jatuhTempo,
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            $this->poster->giroIssued($giro->refresh(), $actor);

            $this->audit->log(
                action: $arah === GiroDirection::Masuk ? 'giro_received' : 'giro_issued',
                subject: $giro,
                newValue: [
                    'nomor' => $giro->nomor,
                    'arah' => $arah->value,
                    'bank_penerbit' => $bank,
                    'nomor_warkat' => $warkat,
                    'nilai_rupiah' => $nilaiRupiah,
                    'tanggal_jatuh_tempo' => $jatuhTempo->toDateString(),
                ],
                actor: $actor,
                alasan: $catatan,
            );

            return $giro->refresh();
        });
    }

    /**
     * The same piece of paper may not be registered twice.
     *
     * The database enforces this too, and this check exists so the person
     * typing gets a sentence about a giro rather than a constraint violation.
     * Entering one twice would double an asset that does not exist.
     */
    private function assertWarkatIsNew(string $bank, string $warkat): void
    {
        $existing = Giro::query()
            ->where('bank_penerbit', $bank)
            ->where('nomor_warkat', $warkat)
            ->first();

        if ($existing !== null) {
            throw new DomainException(sprintf(
                'Giro %s dari %s sudah pernah dicatat sebagai %s (%s).',
                $warkat,
                $bank,
                $existing->nomor,
                $existing->status->label(),
            ));
        }
    }

    /**
     * Bank names are typed by hand and the uniqueness check depends on them.
     *
     * "BCA", "bca" and " Bca " are one bank, and without folding them together
     * the same warkat could be entered three times.
     */
    private function normaliseBank(string $bank): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $bank) ?? ''));
    }

    private function assertMayHandle(User $actor): void
    {
        if (! $actor->role()->canHandleGiro()) {
            throw new DomainException('Anda tidak berhak menangani bilyet giro.');
        }
    }

    private function assertOpen(Giro $giro): void
    {
        if (! $giro->isOpen()) {
            throw new DomainException(
                "Giro {$giro->nomor} sudah {$giro->status->label()} dan tidak bisa diubah lagi."
            );
        }
    }
}
