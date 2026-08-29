<?php

declare(strict_types=1);

namespace App\Domain\Komisi;

use App\Domain\Access\Role;
use App\Domain\Audit\AuditLogger;
use App\Models\CommissionRate;
use App\Models\SalesTarget;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * The Owner's two levers on seller pay: the rate, and the target.
 *
 * Rates are append-only — a change is a new effective-dated row, so the
 * report for any past month keeps computing with the rate that was true
 * then. Targets are a plan, not a ledger: re-setting a month's target
 * replaces it, with old and new in the audit log.
 *
 * Owner only. What a colleague earns per rupiah collected is exactly the
 * kind of number the role matrix exists to fence.
 */
class KomisiSetter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function setRate(User $seat, int $basisPoin, Carbon $berlakuMulai, User $actor): CommissionRate
    {
        $this->assertOwner($actor);
        $this->assertSeat($seat);

        if ($basisPoin < 0 || $basisPoin > 10_000) {
            throw new DomainException('Tarif komisi antara 0 dan 10.000 basis poin (0–100%).');
        }

        $existing = CommissionRate::query()
            ->where('user_id', $seat->id)
            ->whereDate('berlaku_mulai', $berlakuMulai->toDateString())
            ->first();

        if ($existing !== null) {
            throw new DomainException(
                "Sudah ada tarif untuk {$seat->name} mulai {$berlakuMulai->format('d/m/Y')} — "
                .'pilih tanggal mulai lain.'
            );
        }

        $rate = CommissionRate::create([
            'user_id' => $seat->id,
            'basis_poin' => $basisPoin,
            'berlaku_mulai' => $berlakuMulai->toDateString(),
            'set_by' => $actor->id,
        ]);

        $this->audit->log(
            action: 'commission_rate_set',
            subject: $rate,
            newValue: [
                'user' => $seat->name,
                'basis_poin' => $basisPoin,
                'berlaku_mulai' => $berlakuMulai->toDateString(),
            ],
            actor: $actor,
        );

        return $rate;
    }

    public function setTarget(User $seat, int $tahun, int $bulan, int $targetRupiah, User $actor): SalesTarget
    {
        $this->assertOwner($actor);

        if ($seat->role() !== Role::Sales) {
            throw new DomainException('Target penjualan dipasang pada kursi Sales.');
        }

        if ($targetRupiah < 0) {
            throw new DomainException('Target tidak bisa negatif.');
        }

        $target = SalesTarget::query()->firstOrNew([
            'user_id' => $seat->id, 'tahun' => $tahun, 'bulan' => $bulan,
        ]);

        $lama = $target->exists ? (int) $target->target_rupiah : null;

        $target->fill(['target_rupiah' => $targetRupiah, 'set_by' => $actor->id])->save();

        $this->audit->log(
            action: 'sales_target_set',
            subject: $target,
            oldValue: $lama === null ? null : ['target_rupiah' => $lama],
            newValue: [
                'user' => $seat->name,
                'tahun' => $tahun,
                'bulan' => $bulan,
                'target_rupiah' => $targetRupiah,
            ],
            actor: $actor,
        );

        return $target;
    }

    /** The rate a person is on today, in basis points. */
    public function currentRate(User $seat): int
    {
        return (int) (CommissionRate::query()
            ->where('user_id', $seat->id)
            ->whereDate('berlaku_mulai', '<=', Carbon::now()->toDateString())
            ->orderByDesc('berlaku_mulai')
            ->value('basis_poin') ?? 0);
    }

    private function assertOwner(User $actor): void
    {
        if ($actor->role() !== Role::Owner) {
            throw new DomainException('Hanya Pemilik yang mengatur tarif komisi dan target.');
        }
    }

    private function assertSeat(User $seat): void
    {
        if (! in_array($seat->role(), [Role::Sales, Role::Marketing], true)) {
            throw new DomainException('Komisi hanya untuk kursi Sales dan Marketing.');
        }
    }
}
