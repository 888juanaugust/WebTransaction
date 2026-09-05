<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Access\Role;
use App\Domain\Integrity\LedgerIntegrity;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ask the ledgers nightly whether they still add up, and say so when they do
 * not.
 *
 * The checks all existed and none of them ran. That is the failure this job
 * exists to end: a drift between a cached column and the ledger behind it does
 * not announce itself — stock quietly stops matching the shelf, or a control
 * account stops matching its subledger, and the first anybody hears of it is a
 * stock count months later or an accountant at year end.
 *
 * **It never repairs anything.** Rebuilding a cache from the ledger would make
 * the symptom vanish and leave the cause — something writing outside the
 * domain classes — to do it again next week, unwitnessed. The finding is the
 * product.
 *
 * Idempotent, as every job here must be: it only reads and notifies, so a
 * second run on the same night says the same thing twice rather than doing
 * anything twice.
 */
class SweepLedgerIntegrity implements ShouldQueue
{
    use Queueable;

    public function handle(LedgerIntegrity $integrity): void
    {
        $findings = $integrity->findings();

        if ($findings === []) {
            return;
        }

        // The log first, and unconditionally: notifications can be missed,
        // dismissed, or land on an account nobody opens. The log line is what
        // is still there in a month when somebody asks when this started.
        Log::warning('Ledger integrity findings', [
            'count' => count($findings),
            'findings' => array_map(fn ($f) => [
                'pemeriksaan' => $f->pemeriksaan,
                'wilayah' => $f->wilayah,
                'subjek' => $f->subjek,
                'temuan' => $f->temuan,
            ], $findings),
        ]);

        /*
         * The Owner, and only the Owner. A drifting ledger is not a task
         * anybody can be assigned — it means something wrote outside the
         * domain, which is a question about the system rather than about a
         * customer or a shelf. Sending it to Finance or Inventori would ask
         * people to act on something they cannot fix.
         */
        $owners = User::query()
            ->where('role', Role::Owner->value)
            ->where('is_active', true)
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        $ringkas = collect($findings)
            ->take(3)
            ->map(fn ($f) => "{$f->wilayah} · {$f->subjek}: {$f->temuan}")
            ->implode(' ');

        $sisa = count($findings) - min(3, count($findings));

        foreach ($owners as $owner) {
            Notification::make()
                ->title(count($findings).' selisih pada pemeriksaan buku')
                ->body($ringkas.($sisa > 0 ? " (+{$sisa} lainnya)" : '')
                    .' Jalankan `php artisan integritas:periksa` untuk daftar lengkapnya.')
                ->danger()
                ->sendToDatabase($owner);
        }
    }
}
