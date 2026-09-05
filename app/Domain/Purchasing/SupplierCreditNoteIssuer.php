<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording a credit note the supplier sent us.
 *
 * The gap: the three-way match already flags a supplier billing more than the
 * goods were received at, and there was no way to settle it except a purchase
 * return — which takes stock off the shelf that never left. So a pure price
 * dispute had to be resolved by pretending goods went back, or by leaving the
 * payable overstated and netting it off a later payment, which produces a
 * payment figure nobody can reconcile afterwards.
 *
 * A draft stage, unlike the expense screen. The reason is who issued the
 * document: an expense is money we already spent, but this is a piece of paper
 * from a supplier, and the numbers on it are worth checking against the bill
 * before they move a payable. Posting is the separate act.
 */
class SupplierCreditNoteIssuer
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function draft(
        Supplier $supplier,
        DateTimeInterface $tanggal,
        string $accountCode,
        int $dasarRupiah,
        string $alasan,
        User $actor,
        ?SupplierBill $bill = null,
        int $ppnRupiah = 0,
        ?string $nomorNotaSupplier = null,
        ?string $catatan = null,
    ): SupplierCreditNote {
        $this->assertMayIssue($actor);

        if ($dasarRupiah <= 0) {
            throw new DomainException('Nilai nota kredit harus lebih dari nol.');
        }

        if ($ppnRupiah < 0) {
            throw new DomainException('PPN tidak boleh negatif.');
        }

        if (trim($alasan) === '') {
            throw new DomainException('Nota kredit pemasok harus menyebutkan alasannya.');
        }

        if ($bill !== null && (int) $bill->supplier_id !== (int) $supplier->id) {
            throw new DomainException(
                "Tagihan {$bill->nomor} bukan milik {$supplier->nama}."
            );
        }

        $account = $this->assertCreditableAccount($accountCode);
        $date = Carbon::parse($tanggal)->startOfDay();

        if ($date->isFuture()) {
            throw new DomainException('Tanggal nota kredit belum lewat.');
        }

        $note = SupplierCreditNote::create([
            'nomor' => $this->numbers->nextSupplierCreditNoteNumber($date),
            'supplier_id' => $supplier->id,
            'supplier_bill_id' => $bill?->id,
            'tanggal' => $date,
            'nomor_nota_supplier' => $nomorNotaSupplier,
            'account_id' => $account->id,
            'dasar_rupiah' => $dasarRupiah,
            'ppn_rupiah' => $ppnRupiah,
            'ada_faktur_pajak_retur' => $ppnRupiah > 0,
            'total_rupiah' => $dasarRupiah + $ppnRupiah,
            'alasan' => trim($alasan),
            'catatan' => $catatan,
            'created_by' => $actor->id,
        ]);

        $this->audit->log(
            action: 'supplier_credit_note_drafted',
            subject: $note,
            newValue: [
                'nomor' => $note->nomor,
                'pemasok' => $supplier->nama,
                'total_rupiah' => $note->total_rupiah,
                'akun' => $account->kode,
            ],
            actor: $actor,
        );

        return $note->refresh();
    }

    /**
     * Post it: the payable comes down.
     *
     *   Dr Utang Usaha         total
     *     Cr <akun>            the part being unwound
     *     Cr PPN Masukan       only where a faktur pajak retur exists
     */
    public function post(SupplierCreditNote $note, User $actor): SupplierCreditNote
    {
        $this->assertMayIssue($actor);

        if ($note->isPosted()) {
            throw new DomainException("Nota kredit {$note->nomor} sudah diposting.");
        }

        return DB::transaction(function () use ($note, $actor) {
            /*
             * The tagihan first, and it is what makes the check below mean
             * anything.
             *
             * Locking the note alone stops two people posting *the same* note
             * twice — which is what the guard underneath was written for. It
             * does nothing about two *different* notes against one bill, and
             * that is the case that costs money: each read what the supplier
             * still owed, each fitted inside it, and all four posted. Four
             * notes of Rp 10.000.000 against a Rp 10.000.000 bill, measured
             * by forking processes at it.
             *
             * Nothing downstream would have caught it either. A credit note
             * is not a payment, so it never reaches the allocation guard —
             * this is the only place the question is asked.
             *
             * Bill before note, matching the order everywhere else money is
             * applied to a document: the note row carries a
             * `supplier_bill_id`, so writing it takes a key-share lock on the
             * bill, and two of those upgrading to `FOR UPDATE` deadlock
             * instead of queueing.
             */
            if ($note->supplier_bill_id !== null) {
                SupplierBill::query()
                    ->withoutGlobalScope('region')
                    ->whereKey($note->supplier_bill_id)
                    ->lockForUpdate()
                    ->first();
            }

            $locked = SupplierCreditNote::query()->lockForUpdate()->findOrFail($note->id);

            /*
             * Checked again under the lock. The check above catches the
             * ordinary case; this one catches two people posting the same note
             * at once, which no single-threaded test can reach — mutation
             * testing therefore reports it as removable. It is not: without it
             * a payable would come down twice for one piece of paper.
             */
            if ($locked->isPosted()) {
                throw new DomainException("Nota kredit {$locked->nomor} sudah diposting.");
            }

            /*
             * Checked at posting rather than at draft. A note can sit in draft
             * for a week while somebody queries it with the supplier, and in
             * that week a payment may have cleared the bill — so what the
             * supplier still owes has to be true at the moment it moves the
             * books, not at the moment it was typed.
             */
            $this->assertWithinWhatIsOwed($locked);

            $locked->forceFill([
                'status' => SupplierCreditNote::STATUS_POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->poster->supplierCreditNotePosted($locked->refresh(), $actor);

            /*
             * A note can settle a bill outright, and until `amountCredited()`
             * existed nothing noticed: the bill stayed open at its full
             * amount with nothing left owing on it.
             */
            app(SupplierLedger::class)->settleIfCleared($locked->bill);

            $this->audit->log(
                action: 'supplier_credit_note_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'total_rupiah' => $locked->total_rupiah,
                    'tagihan' => $locked->bill?->nomor,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    /** Throw a draft away. Nothing has been posted, so nothing needs unwinding. */
    public function discard(SupplierCreditNote $note, User $actor): void
    {
        $this->assertMayIssue($actor);

        if ($note->isPosted()) {
            throw new DomainException(
                "Nota kredit {$note->nomor} sudah diposting dan tidak bisa dibuang."
            );
        }

        $this->audit->log(
            action: 'supplier_credit_note_discarded',
            subject: $note,
            oldValue: ['nomor' => $note->nomor, 'total_rupiah' => $note->total_rupiah],
            actor: $actor,
        );

        $note->delete();
    }

    /**
     * A credit cannot exceed what is actually owed.
     *
     * Against a named bill it is that bill's outstanding balance; otherwise it
     * is the supplier's whole payable. Without this a mistyped figure — an
     * extra zero — turns a payable negative, which reads as the supplier owing
     * *us* money and will be netted off the next real payment by somebody who
     * cannot see where it came from.
     */
    private function assertWithinWhatIsOwed(SupplierCreditNote $note): void
    {
        $total = (int) $note->total_rupiah;

        if ($note->bill !== null) {
            $outstanding = $note->bill->amountOutstanding();

            if ($total > $outstanding) {
                throw new DomainException(sprintf(
                    'Nota kredit %s melebihi sisa tagihan %s (%s vs %s).',
                    $note->nomor,
                    $note->bill->nomor,
                    number_format($total, 0, ',', '.'),
                    number_format($outstanding, 0, ',', '.'),
                ));
            }

            return;
        }

        $owed = app(SupplierLedger::class)->outstandingFor($note->supplier);

        if ($total > $owed) {
            throw new DomainException(sprintf(
                'Nota kredit %s melebihi total utang ke %s (%s vs %s).',
                $note->nomor,
                $note->supplier->nama,
                number_format($total, 0, ',', '.'),
                number_format($owed, 0, ',', '.'),
            ));
        }
    }

    /**
     * Which account a credit may unwind.
     *
     * Two refusals, each protecting something the books depend on:
     *
     * - **Utang Usaha** is the other side of this entry. Crediting it here
     *   would produce an entry that debits and credits the same account and
     *   changes nothing while appearing to.
     * - **Persediaan** moves only through the stock ledger, at the cost frozen
     *   on each movement. A hand-entered credit would put the inventory account
     *   out against the valuation permanently, and the check that proves them
     *   equal would start failing with no document to explain it. If a supplier
     *   credit really does mean the goods cost less, the instrument is a
     *   purchase return or a landed-cost adjustment, not this.
     */
    private function assertCreditableAccount(string $accountCode): Account
    {
        $account = Account::byCode($accountCode);

        if (! $account->dapat_diposting) {
            throw new DomainException("Akun {$account->kode} adalah akun induk, bukan akun posting.");
        }

        if ($accountCode === AccountCode::UTANG_USAHA) {
            throw new DomainException(
                'Lawan jurnalnya tidak boleh Utang Usaha — itu sisi satunya, dan hasilnya nol.'
            );
        }

        if ($accountCode === AccountCode::PERSEDIAAN) {
            throw new DomainException(
                'Nilai persediaan hanya bergerak lewat buku stok. Untuk barang yang benar-benar '
                .'kembali, pakai retur pembelian.'
            );
        }

        return $account;
    }

    private function assertMayIssue(User $actor): void
    {
        if (! $actor->role()->canRecordPurchases()) {
            throw new DomainException('Anda tidak berhak mencatat nota kredit pemasok.');
        }
    }
}
