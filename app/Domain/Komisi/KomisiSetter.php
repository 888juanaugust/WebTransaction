<?php

declare(strict_types=1);

namespace App\Domain\Komisi;

use App\Domain\Access\Role;
use App\Domain\Audit\AuditLogger;
use App\Models\CommissionRate;
use App\Models\Region;
use App\Models\SalesTarget;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Owner's two levers on commission pay: the rate, and the target.
 *
 * Rates are append-only — a change is a new effective-dated row, so the
 * report for any past month keeps computing with the rate that was true
 * then. Targets are a plan, not a ledger: re-setting a month's target
 * replaces it, with old and new in the audit log.
 *
 * Four kinds of commission (see `JenisKomisi`), and the kind is part of the
 * key: a sales seat who is also the cabang's supervisor holds two rates,
 * one per kind, and a supervisor's rate names the cabang it supervises.
 *
 * Owner only. What a colleague earns per rupiah collected is exactly the
 * kind of number the role matrix exists to fence.
 */
class KomisiSetter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function setRate(
        User $seat,
        int $basisPoin,
        Carbon $berlakuMulai,
        User $actor,
        JenisKomisi $jenis = JenisKomisi::Penjualan,
        ?int $regionId = null,
    ): CommissionRate {
        $this->assertOwner($actor);
        $this->assertPenerima($seat, $jenis);

        if ($basisPoin < 0 || $basisPoin > 10_000) {
            throw new DomainException('Tarif komisi antara 0 dan 10.000 basis poin (0–100%).');
        }

        $region = $this->resolveRegion($jenis, $regionId);

        $existing = CommissionRate::query()
            ->where('user_id', $seat->id)
            ->where('jenis', $jenis->value)
            ->whereDate('berlaku_mulai', $berlakuMulai->toDateString())
            ->first();

        if ($existing !== null) {
            throw new DomainException(
                "Sudah ada tarif {$jenis->label()} untuk {$seat->name} mulai {$berlakuMulai->format('d/m/Y')} — "
                .'pilih tanggal mulai lain.'
            );
        }

        $rate = CommissionRate::create([
            'user_id' => $seat->id,
            'jenis' => $jenis->value,
            'cabang_id' => $region?->id,
            'basis_poin' => $basisPoin,
            'berlaku_mulai' => $berlakuMulai->toDateString(),
            'set_by' => $actor->id,
        ]);

        $this->audit->log(
            action: 'commission_rate_set',
            subject: $rate,
            newValue: [
                'user' => $seat->name,
                'jenis' => $jenis->value,
                'cabang' => $region?->kode,
                'basis_poin' => $basisPoin,
                'berlaku_mulai' => $berlakuMulai->toDateString(),
            ],
            actor: $actor,
        );

        return $rate;
    }

    public function setTarget(
        User $seat,
        int $tahun,
        int $bulan,
        int $targetRupiah,
        User $actor,
        JenisKomisi $jenis = JenisKomisi::Penjualan,
    ): SalesTarget {
        $this->assertOwner($actor);

        // The seat kind's target is a *selling* target, and selling is what
        // the sales seat does; the other kinds are targeted on their own basis.
        if ($jenis === JenisKomisi::Penjualan && $seat->role() !== Role::Sales) {
            throw new DomainException('Target penjualan dipasang pada kursi Sales.');
        }

        if ($jenis !== JenisKomisi::Penjualan) {
            $this->assertPenerima($seat, $jenis);
        }

        if ($targetRupiah < 0) {
            throw new DomainException('Target tidak bisa negatif.');
        }

        $target = SalesTarget::query()->firstOrNew([
            'user_id' => $seat->id, 'jenis' => $jenis->value, 'tahun' => $tahun, 'bulan' => $bulan,
        ]);

        $lama = $target->exists ? (int) $target->target_rupiah : null;

        $target->fill(['target_rupiah' => $targetRupiah, 'set_by' => $actor->id])->save();

        $this->audit->log(
            action: 'sales_target_set',
            subject: $target,
            oldValue: $lama === null ? null : ['target_rupiah' => $lama],
            newValue: [
                'user' => $seat->name,
                'jenis' => $jenis->value,
                'tahun' => $tahun,
                'bulan' => $bulan,
                'target_rupiah' => $targetRupiah,
            ],
            actor: $actor,
        );

        return $target;
    }

    /** The rate a person is on today for a kind, in basis points. */
    public function currentRate(User $seat, JenisKomisi $jenis = JenisKomisi::Penjualan, ?int $regionId = null): int
    {
        return (int) (CommissionRate::query()
            ->where('user_id', $seat->id)
            ->where('jenis', $jenis->value)
            ->when($regionId !== null, fn ($q) => $q->where('cabang_id', $regionId))
            ->whereDate('berlaku_mulai', '<=', Carbon::now()->toDateString())
            ->orderByDesc('berlaku_mulai')
            ->value('basis_poin') ?? 0);
    }

    /**
     * Every (person, kind, cabang) that has ever been given a non-seat rate —
     * what the Owner's screen lists under the three new kinds.
     *
     * @return Collection<int, array{user: User, jenis: JenisKomisi, region: ?Region}>
     */
    public function lainnya(): Collection
    {
        return CommissionRate::query()
            ->where('jenis', '!=', JenisKomisi::Penjualan->value)
            ->with(['user', 'region'])
            ->get()
            ->filter(fn (CommissionRate $r) => $r->user !== null)
            ->unique(fn (CommissionRate $r) => $r->user_id.'|'.$r->jenis.'|'.($r->cabang_id ?? ''))
            ->sortBy(fn (CommissionRate $r) => [$r->jenis, $r->user->name])
            ->values()
            ->map(fn (CommissionRate $r) => [
                'user' => $r->user,
                'jenis' => $r->jenis(),
                'region' => $r->region,
            ]);
    }

    private function assertOwner(User $actor): void
    {
        if ($actor->role() !== Role::Owner) {
            throw new DomainException('Hanya Pemilik yang mengatur tarif komisi dan target.');
        }
    }

    /**
     * Who may receive a kind of commission.
     *
     * The seat kind is the customer's team — Sales and Marketing, nobody
     * else. The other three are jobs, not seats: a supervisor is usually a
     * sales person promoted, the import book is often Finance or Inventori,
     * so any active staff account qualifies — except the Owner, whose pay is
     * the business itself.
     */
    private function assertPenerima(User $seat, JenisKomisi $jenis): void
    {
        if ($jenis->perKursi()) {
            if (! in_array($seat->role(), [Role::Sales, Role::Marketing], true)) {
                throw new DomainException('Komisi penjualan hanya untuk kursi Sales dan Marketing.');
            }

            return;
        }

        if ($seat->role() === Role::Owner) {
            throw new DomainException('Pemilik tidak menerima komisi.');
        }

        if (! $seat->is_active) {
            throw new DomainException("Akun {$seat->name} tidak aktif.");
        }
    }

    private function resolveRegion(JenisKomisi $jenis, ?int $regionId): ?Region
    {
        if ($jenis->butuhCabang()) {
            $region = $regionId === null ? null : Region::query()->find($regionId);

            if ($region === null || ! $region->aktif) {
                throw new DomainException('Komisi supervisor harus menyebut cabang yang aktif.');
            }

            return $region;
        }

        if ($regionId !== null) {
            throw new DomainException("Komisi {$jenis->label()} tidak memakai cabang.");
        }

        return null;
    }
}
