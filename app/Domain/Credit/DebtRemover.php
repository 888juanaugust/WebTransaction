<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Access\Role;
use App\Domain\Audit\AuditLogger;
use App\Domain\Payments\PaymentLedger;
use App\Models\DebtRemoval;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Penghapusan piutang: settling a debt that was paid outside the system.
 *
 * A customer hands cash to the marketing who visits them — that is how credit
 * sales work here — and the system finds out after the fact. Two statements
 * make it true: the marketing in charge claims the money was received, and
 * finance verifies it. Neither person alone can make a debt disappear, and
 * the two can never be the same person.
 *
 * Approval does not touch the invoice. It posts an ordinary manual payment
 * through the payment ledger — append-only, journalled, settling the invoice
 * and advancing its order the same way a bank transfer would. The removal row
 * is the authorisation trail, not the money.
 */
class DebtRemover
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The marketing in charge (or the Owner) claims a debt was paid outside.
     *
     * @param  int  $amountRupiah  what was actually handed over — at most the
     *                             invoice's outstanding balance
     * @param  string  $alasan  where, when and in what form; this is the
     *                          statement finance will verify
     */
    public function initiate(Invoice $invoice, User $actor, int $amountRupiah, string $alasan): DebtRemoval
    {
        $this->assertMayInitiate($invoice, $actor);

        if ($invoice->status !== Invoice::STATUS_OPEN) {
            throw new \DomainException("Faktur {$invoice->nomor} tidak terbuka — tidak ada piutang untuk dihapus.");
        }

        $sisa = $invoice->amountOutstanding();

        if ($amountRupiah < 1 || $amountRupiah > $sisa) {
            throw new \DomainException(sprintf(
                'Jumlah penghapusan harus antara 1 dan sisa tagihan %s.',
                number_format($sisa, 0, ',', '.'),
            ));
        }

        if (trim($alasan) === '') {
            throw new \DomainException('Tulis bagaimana uangnya diterima — itulah yang akan diverifikasi finance.');
        }

        if (DebtRemoval::query()->where('invoice_id', $invoice->id)->where('status', DebtRemovalStatus::Diajukan)->exists()) {
            throw new \DomainException("Faktur {$invoice->nomor} sudah punya pengajuan penghapusan yang menunggu verifikasi.");
        }

        return DB::transaction(function () use ($invoice, $actor, $amountRupiah, $alasan) {
            $removal = DebtRemoval::query()->create([
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'amount_rupiah' => $amountRupiah,
                'alasan' => $alasan,
                'initiated_by' => $actor->id,
            ]);

            $this->audit->log(
                action: 'debt_removal_initiated',
                subject: $removal,
                newValue: ['invoice' => $invoice->nomor, 'amount_rupiah' => $amountRupiah],
                actor: $actor,
                alasan: $alasan,
            );

            return $removal;
        });
    }

    /**
     * Finance verifies the money is real. This is the click that posts it.
     */
    public function approve(DebtRemoval $removal, User $actor, ?string $catatan = null): DebtRemoval
    {
        $this->assertMayDecide($removal, $actor);

        $invoice = $removal->invoice;

        /*
         * The balance may have moved since the claim — a transfer landed, a
         * credit note posted. Approving more than what is owed would push the
         * invoice into credit on the say-so of a stale number, so the claim
         * goes back to marketing to re-file at today's figure.
         */
        if ($removal->amount_rupiah > $invoice->amountOutstanding()) {
            throw new \DomainException(sprintf(
                'Sisa tagihan %s sekarang lebih kecil dari pengajuan %s — tolak pengajuan ini dan minta marketing mengajukan ulang dengan angka hari ini.',
                number_format($invoice->amountOutstanding(), 0, ',', '.'),
                number_format($removal->amount_rupiah, 0, ',', '.'),
            ));
        }

        return DB::transaction(function () use ($removal, $actor, $catatan, $invoice) {
            $entry = $this->ledger->recordManualPayment(
                company: $removal->company,
                amountRupiah: $removal->amount_rupiah,
                actor: $actor,
                invoice: $invoice,
                catatan: "Penghapusan piutang — {$removal->alasan}",
            );

            $removal->forceFill([
                'status' => DebtRemovalStatus::Disetujui,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'keputusan_catatan' => $catatan,
                'payment_entry_id' => $entry->id,
            ])->save();

            $this->audit->log(
                action: 'debt_removal_approved',
                subject: $removal,
                newValue: [
                    'invoice' => $invoice->nomor,
                    'amount_rupiah' => $removal->amount_rupiah,
                    'payment_entry_id' => $entry->id,
                ],
                actor: $actor,
                alasan: $catatan,
            );

            return $removal;
        });
    }

    /**
     * Finance says no. The claim closes; the invoice is free for a new one.
     */
    public function reject(DebtRemoval $removal, User $actor, string $catatan): DebtRemoval
    {
        $this->assertMayDecide($removal, $actor);

        if (trim($catatan) === '') {
            throw new \DomainException('Tulis kenapa ditolak — marketing yang mengajukan harus tahu apa yang salah.');
        }

        return DB::transaction(function () use ($removal, $actor, $catatan) {
            $removal->forceFill([
                'status' => DebtRemovalStatus::Ditolak,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'keputusan_catatan' => $catatan,
            ])->save();

            $this->audit->log(
                action: 'debt_removal_rejected',
                subject: $removal,
                newValue: ['invoice' => $removal->invoice->nomor, 'amount_rupiah' => $removal->amount_rupiah],
                actor: $actor,
                alasan: $catatan,
            );

            return $removal;
        });
    }

    /**
     * Claiming is the seat of the marketing in charge, exactly like approving
     * the customer's orders — they are the one the customer hands money to.
     */
    private function assertMayInitiate(Invoice $invoice, User $actor): void
    {
        if ($actor->role() === Role::Owner) {
            return;
        }

        if ($actor->role() !== Role::Marketing) {
            throw new \DomainException('Mengajukan penghapusan piutang hanya bisa dilakukan marketing penanggung jawab pelanggan (atau pemilik).');
        }

        $marketingId = $invoice->company->marketing_user_id;

        if ($marketingId === null || (int) $marketingId !== (int) $actor->getKey()) {
            throw new \DomainException("Pelanggan {$invoice->company->nama} bukan tanggung jawab Anda — pengajuan penghapusan piutangnya bukan hak Anda.");
        }
    }

    /**
     * Deciding is finance's seat, and never the initiator's — the whole point
     * is that the person who claims the money cannot also declare it real.
     */
    private function assertMayDecide(DebtRemoval $removal, User $actor): void
    {
        if ($removal->status !== DebtRemovalStatus::Diajukan) {
            throw new \DomainException("Pengajuan ini sudah {$removal->status->label()}.");
        }

        if (! $actor->role()->canConfirmPayment()) {
            throw new \DomainException('Memverifikasi penghapusan piutang adalah keputusan finance — peran Anda tidak bisa.');
        }

        if ((int) $removal->initiated_by === (int) $actor->getKey()) {
            throw new \DomainException('Yang mengajukan tidak boleh memverifikasi pengajuannya sendiri — dua kunci, dua orang.');
        }

    }
}
