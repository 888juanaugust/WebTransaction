<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Audit\AuditLogger;
use App\Domain\Regions\RegionContext;
use App\Models\SalesExpenseClaim;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Biaya ekspedisi: a sales claims their own road spending, finance verifies.
 *
 * The sales cannot write into the books — recording expenses is finance's
 * pen — so their spending arrives as a claim: what, when, how much. Finance
 * checks it by hand (the receipts, the route, the fuel math) and their
 * approval posts an ordinary expense through ExpenseRecorder, onto the
 * ongkos-kirim account, paid from kas or bank as finance says. The claim row
 * keeps who said and who checked; the expense document is the money.
 */
class SalesExpenseClaims
{
    public function __construct(
        private readonly ExpenseRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    /** The sales files their own spending — never somebody else's. */
    public function file(User $sales, DateTimeInterface $tanggal, int $amountRupiah, string $keterangan): SalesExpenseClaim
    {
        if ($sales->role() !== Role::Sales) {
            throw new \DomainException('Klaim biaya ekspedisi diajukan oleh sales untuk pengeluarannya sendiri.');
        }

        if ($amountRupiah <= 0) {
            throw new \DomainException('Nilai klaim harus lebih dari nol.');
        }

        if (trim($keterangan) === '') {
            throw new \DomainException('Tulis untuk apa uangnya — itulah yang akan dicek finance.');
        }

        if (Carbon::parse($tanggal)->startOfDay()->isFuture()) {
            throw new \DomainException('Tanggal klaim tidak boleh di masa depan.');
        }

        // Pinned to the sales' own region: the claim belongs to the books
        // of the region the road belongs to.
        return app(RegionContext::class)->within(
            (int) $sales->region_id,
            fn () => DB::transaction(function () use ($sales, $tanggal, $amountRupiah, $keterangan) {
                $claim = SalesExpenseClaim::query()->create([
                    'sales_user_id' => $sales->id,
                    'tanggal' => Carbon::parse($tanggal)->toDateString(),
                    'amount_rupiah' => $amountRupiah,
                    'keterangan' => trim($keterangan),
                ]);

                $this->audit->log(
                    action: 'sales_expense_claimed',
                    subject: $claim,
                    newValue: ['amount_rupiah' => $amountRupiah, 'keterangan' => trim($keterangan)],
                    actor: $sales,
                );

                return $claim;
            }),
        );
    }

    /**
     * Finance verified the spending by hand; this is the click that posts it.
     */
    public function approve(SalesExpenseClaim $claim, User $actor, PaidFrom $dibayarDari, ?string $catatan = null): SalesExpenseClaim
    {
        $this->assertMayDecide($claim, $actor);

        return app(RegionContext::class)->within(
            (int) $claim->region_id,
            fn () => DB::transaction(function () use ($claim, $actor, $dibayarDari, $catatan) {
                $expense = $this->recorder->record(
                    tanggal: $claim->tanggal,
                    accountCode: AccountCode::BEBAN_ONGKOS_KIRIM,
                    paidFrom: $dibayarDari,
                    amountRupiah: $claim->amount_rupiah,
                    keterangan: "Ekspedisi {$claim->sales->name} — {$claim->keterangan}",
                    actor: $actor,
                    referensi: "klaim-ekspedisi-{$claim->id}",
                );

                $claim->forceFill([
                    'status' => ClaimStatus::Disetujui,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'keputusan_catatan' => $catatan,
                    'expense_id' => $expense->id,
                ])->save();

                $this->audit->log(
                    action: 'sales_expense_approved',
                    subject: $claim,
                    newValue: [
                        'amount_rupiah' => $claim->amount_rupiah,
                        'expense_id' => $expense->id,
                        'dibayar_dari' => $dibayarDari->value,
                    ],
                    actor: $actor,
                    alasan: $catatan,
                );

                return $claim;
            }),
        );
    }

    /** Finance says no, with a reason the sales will read. */
    public function reject(SalesExpenseClaim $claim, User $actor, string $catatan): SalesExpenseClaim
    {
        $this->assertMayDecide($claim, $actor);

        if (trim($catatan) === '') {
            throw new \DomainException('Tulis kenapa ditolak — sales yang mengajukan harus tahu apa yang salah.');
        }

        return DB::transaction(function () use ($claim, $actor, $catatan) {
            $claim->forceFill([
                'status' => ClaimStatus::Ditolak,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'keputusan_catatan' => $catatan,
            ])->save();

            $this->audit->log(
                action: 'sales_expense_rejected',
                subject: $claim,
                newValue: ['amount_rupiah' => $claim->amount_rupiah],
                actor: $actor,
                alasan: $catatan,
            );

            return $claim;
        });
    }

    private function assertMayDecide(SalesExpenseClaim $claim, User $actor): void
    {
        if ($claim->status !== ClaimStatus::Diajukan) {
            throw new \DomainException("Klaim ini sudah {$claim->status->label()}.");
        }

        // Recording expenses is finance's pen (canPostJournals — the same
        // gate ExpenseRecorder enforces); the same seat verifies these. The
        // filer can never hold it — Sales is not that seat.
        if (! $actor->role()->canPostJournals()) {
            throw new \DomainException('Memverifikasi biaya ekspedisi adalah keputusan finance.');
        }
    }
}
