<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Credit\DebtAging;
use App\Models\Invoice;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The nightly walk over aging debt.
 *
 * Finds open invoices that crossed the three-month line and have not been
 * flagged, marks each once, and tells the two people who own the problem —
 * the sales and marketing in charge of that customer — through the panel's
 * bell. The customer's own warning is not sent from here: the portal computes
 * it live on every visit, which cannot go stale and cannot be missed the way
 * a single notification can.
 *
 * The freeze needs no sweep at all. Fall-due is derived arithmetic — the
 * credit check and the portal both compute it at the moment of asking, so
 * there is no state to advance and nothing here to forget.
 *
 * Idempotent the boring way: the claim is `whereNull(debt_notified_at)` and
 * the mark is a conditional UPDATE, so two overlapping runs cannot notify
 * twice. Runs unbound — debt ages in every region on the same calendar.
 */
class SweepDebtAging implements ShouldQueue
{
    use Queueable;

    public function handle(DebtAging $aging): void
    {
        foreach ($aging->needingNotice() as $invoice) {
            /*
             * Conditional update as the claim: only the run that flips the
             * NULL sends the notices. A crashed run that marked but did not
             * notify loses that invoice's notice — accepted, because the
             * reverse order would double-notify on every retry, and the
             * receivables screens still show the same figure.
             */
            $claimed = DB::table('invoices')
                ->where('id', $invoice->id)
                ->whereNull('debt_notified_at')
                ->update(['debt_notified_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            $this->notifyTeam($invoice);
        }
    }

    private function notifyTeam(Invoice $invoice): void
    {
        $company = $invoice->company;
        $tim = collect([$company?->salesRep, $company?->marketingRep])
            ->filter(fn (?User $u) => $u !== null && $u->is_active);

        if ($tim->isEmpty()) {
            // A debt aging at a customer with no team is the owner's problem
            // to see — it stays visible in the receivables queue, and the log
            // records that nobody was told directly.
            Log::info('Debt aged three months at a customer with no team assigned', [
                'invoice' => $invoice->nomor,
                'company_id' => $invoice->company_id,
            ]);

            return;
        }

        /*
         * Normally the sweep meets an invoice the night it turns 120 days
         * old, and "30 hari lagi" is the truth. But the first invoice it
         * ever sees may already be past 150 — a backdated faktur, a
         * customer assigned a team late — and telling the team "30 days
         * left" about a customer who is already locked would cost the
         * warning its credibility.
         */
        $sudahTerkunci = $invoice->issued_on->lte(app(DebtAging::class)->freezeCutoff());
        $sisaHari = (int) config('penjualan.debt_freeze_days') - (int) config('penjualan.debt_notice_days');

        foreach ($tim as $anggota) {
            $notice = Notification::make()
                // "Melewati", not "berumur": true on the normal night it
                // turns 120 days, and still true for the late-seen invoice
                // that is already older.
                ->title("Piutang {$company->nama} melewati "
                    .config('penjualan.debt_notice_days').' hari')
                ->body(sprintf(
                    'Faktur %s, sisa %s, terbit %s. %s',
                    $invoice->nomor,
                    number_format($invoice->amountOutstanding(), 0, ',', '.'),
                    $invoice->issued_on->format('d/m/Y'),
                    $sudahTerkunci
                        ? 'Pelanggan ini sudah terkunci dari transaksi baru sampai faktur lunas.'
                        : "{$sisaHari} hari lagi pelanggan ini terkunci dari transaksi baru.",
                ));

            ($sudahTerkunci ? $notice->danger() : $notice->warning())
                ->sendToDatabase($anggota);
        }
    }
}
