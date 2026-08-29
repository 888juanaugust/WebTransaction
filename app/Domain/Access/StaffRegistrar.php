<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Staff accounts: hiring somebody, changing what they can do, and shutting
 * them off when they leave.
 *
 * This existed only as a `php artisan tinker` session on the production box
 * until now, which meant three things were true at once: nobody could be hired
 * without a developer, nobody locked out could be let back in, and a leaver's
 * account stayed open because closing it was somebody else's job. All three
 * are ordinary Tuesday problems, and none of them should need SSH.
 *
 * Everything here writes to the audit log. That is not decoration. The role
 * matrix in CLAUDE.md is the whole of this system's internal control — whoever
 * confirms a payment must not be able to edit the invoice amount — and this
 * class is the one place those assignments can move. "Who gave Finance the
 * Sales role, and when" is precisely the question the audit log exists to
 * answer, so the answer gets written down as the change is made.
 *
 * Passwords are audited as events, never as values. The log records that a
 * password was set and who set it; the password itself is not written down
 * anywhere the log can be read, and the log is readable by the Owner.
 */
class StaffRegistrar
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(
        string $nama,
        string $email,
        Role $role,
        string $password,
        ?User $actor = null,
        ?int $regionId = null,
        ?int $warehouseId = null,
    ): User {
        $warehouse = $this->resolveWarehouseBinding($role, $warehouseId);

        $staff = User::create([
            'name' => $nama,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
            // Null means every region — the Owner's posture, and marketing's
            // too: marketing is global, answers for customers in every
            // region, and carries no region of its own. Anyone else with
            // null is pinned to the default region by the middleware, so an
            // unassigned clerk sees one region, never all of them.
            // A packer's region is not a choice at all: it is wherever their
            // gudang stands.
            'region_id' => $warehouse?->region_id
                ?? (in_array($role, [Role::Owner, Role::Marketing], true) ? null : $regionId),
            'warehouse_id' => $warehouse?->id,
        ]);

        $this->audit->log(
            action: 'staff_created',
            subject: $staff,
            newValue: [
                'name' => $nama,
                'email' => $email,
                'role' => $role->value,
                'region_id' => $staff->region_id,
            ],
            actor: $actor,
        );

        return $staff;
    }

    /**
     * Move somebody to a different region — or, for an Owner, to none.
     *
     * Audited on its own key rather than folded into a rename: which region an
     * account can see is an access boundary, the same kind of fact as its
     * role. Only the Owner reaches this (the screen is Owner-only), which is
     * what the new organisation asks: regions are assigned by admin alone.
     */
    public function assignRegion(User $staff, ?int $regionId, ?User $actor = null): void
    {
        $lama = $staff->region_id === null ? null : (int) $staff->region_id;

        if ($lama === $regionId) {
            return;
        }

        $this->refuseSelf($staff, $actor ?? auth()->user(), 'Wilayah sendiri tidak bisa diubah dari layar ini.');

        if ($staff->role() === Role::Marketing && $regionId !== null) {
            throw new RuntimeException(
                'Marketing bersifat global — melihat semua wilayah dan tidak bisa dipatok ke satu wilayah.'
            );
        }

        $staff->forceFill(['region_id' => $regionId])->save();

        $this->audit->log(
            action: 'staff_region_changed',
            subject: $staff,
            oldValue: ['region_id' => $lama],
            newValue: ['region_id' => $regionId],
            actor: $actor,
        );

        /*
         * Their open session was showing the old region. End it, so the next
         * page load re-binds from the account rather than carrying on reading
         * books that are no longer theirs.
         */
        $this->endSessions($staff);
    }

    /**
     * Change the name or email on an account.
     *
     * Separated from changeRole() because they are different kinds of event.
     * This one is a typo being fixed or somebody getting married; that one
     * moves a person across the control boundary. Folding them into a single
     * "user updated" entry would bury the second inside the first.
     */
    public function rename(User $staff, string $nama, string $email, ?User $actor = null): void
    {
        $old = ['name' => $staff->name, 'email' => $staff->email];
        $new = ['name' => $nama, 'email' => $email];

        if ($old === $new) {
            return;
        }

        $staff->forceFill($new)->save();

        $this->audit->log(
            action: 'staff_renamed',
            subject: $staff,
            oldValue: $old,
            newValue: $new,
            actor: $actor,
        );
    }

    public function changeRole(User $staff, Role $role, ?User $actor = null, ?string $alasan = null): void
    {
        $actor ??= auth()->user();
        $lama = $staff->role;

        if ($lama === $role) {
            return;
        }

        $this->refuseSelf($staff, $actor, 'Peran sendiri tidak bisa diubah dari layar ini.');
        $this->refuseLastOwner($staff);

        if ($role->isWarehouseBound() && $staff->warehouse_id === null) {
            throw new RuntimeException(
                'Peran Gudang terikat ke satu gudang — pilih gudangnya dulu lewat penugasan gudang.'
            );
        }

        if ($role->isWarehouseBound()) {
            $this->refuseSecondPacker((int) $staff->warehouse_id, $staff);

            // A packer's region is their warehouse's region, always.
            $staff->forceFill([
                'region_id' => Warehouse::query()->findOrFail($staff->warehouse_id)->region_id,
            ]);
        }

        $staff->forceFill(['role' => $role])->save();

        $this->audit->log(
            action: 'staff_role_changed',
            subject: $staff,
            oldValue: ['role' => $lama->value],
            newValue: ['role' => $role->value],
            actor: $actor,
            alasan: $alasan,
        );
    }

    /**
     * Bind a Gudang account to its warehouse, or move it to another one.
     *
     * The region follows the warehouse — a packer's region is wherever their
     * gudang stands, never a separate choice that could disagree with it.
     */
    public function assignWarehouse(User $staff, int $warehouseId, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        if (! $staff->role()->isWarehouseBound()) {
            throw new RuntimeException('Hanya akun peran Gudang yang diikat ke satu gudang.');
        }

        if ((int) $staff->warehouse_id === $warehouseId) {
            return;
        }

        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $this->refuseSecondPacker($warehouseId, $staff);

        $lama = $staff->warehouse_id === null ? null : (int) $staff->warehouse_id;

        $staff->forceFill([
            'warehouse_id' => $warehouse->id,
            'region_id' => $warehouse->region_id,
        ])->save();

        $this->audit->log(
            action: 'staff_warehouse_changed',
            subject: $staff,
            oldValue: ['warehouse_id' => $lama],
            newValue: ['warehouse_id' => $warehouse->id],
            actor: $actor,
        );

        // Their open session was showing the old warehouse's queue.
        $this->endSessions($staff);
    }

    /**
     * "Each warehouse has one admin which holds this role account": a second
     * active packer on the same gudang would make "who packed this" a
     * question with two answers.
     */
    private function refuseSecondPacker(int $warehouseId, ?User $except = null): void
    {
        $taken = User::query()
            ->where('role', Role::Storage)
            ->where('is_active', true)
            ->where('warehouse_id', $warehouseId)
            ->when($except?->exists, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->first();

        if ($taken !== null) {
            throw new RuntimeException(
                "Gudang itu sudah dipegang {$taken->name} — satu gudang satu akun Gudang. "
                .'Nonaktifkan akun lamanya dulu.'
            );
        }
    }

    /** The warehouse a new account binds to, validated for the role. */
    private function resolveWarehouseBinding(Role $role, ?int $warehouseId): ?Warehouse
    {
        if (! $role->isWarehouseBound()) {
            return null;
        }

        if ($warehouseId === null) {
            throw new RuntimeException('Peran Gudang harus dipilihkan gudangnya.');
        }

        $this->refuseSecondPacker($warehouseId);

        return Warehouse::query()->findOrFail($warehouseId);
    }

    /**
     * Set somebody else's password, because they cannot get in to set it
     * themselves.
     *
     * This is a worse mechanism than a reset link and it is here because the
     * better one needs working mail, which this deployment does not yet have.
     * Its weakness is inherent and worth naming: for a moment the Owner knows
     * a member of staff's password. The profile page exists so that moment
     * ends — the staff member changes it at their next login and the Owner's
     * copy stops being true.
     *
     * What the audit log gets is the event, not the value.
     */
    public function setPassword(User $staff, string $password, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        $staff->forceFill(['password' => $password])->save();

        /*
         * Anyone holding a "remember me" cookie for this account can still
         * walk back in on the old credential, which would make the reset a
         * reset of nothing. Rotating the token invalidates those cookies.
         */
        $staff->setRememberToken(Str::random(60));
        $staff->save();

        $this->audit->log(
            action: 'staff_password_reset',
            subject: $staff,
            newValue: ['oleh' => $actor?->name],
            actor: $actor,
        );
    }

    public function deactivate(User $staff, ?User $actor = null, ?string $alasan = null): void
    {
        $actor ??= auth()->user();

        if (! $staff->is_active) {
            return;
        }

        $this->refuseSelf($staff, $actor, 'Akun sendiri tidak bisa dinonaktifkan.');
        $this->refuseLastOwner($staff);

        $staff->forceFill(['is_active' => false])->save();

        /*
         * Panel access is checked per request through User::canAccessPanel(),
         * so a session already open goes dead on its next page load rather
         * than at its next login. Clearing the session rows makes that
         * immediate instead of nearly-immediate, which matters on the one
         * occasion this is used in anger: somebody being walked out.
         */
        $this->endSessions($staff);

        $this->audit->log(
            action: 'staff_deactivated',
            subject: $staff,
            oldValue: ['is_active' => true],
            newValue: ['is_active' => false],
            actor: $actor,
            alasan: $alasan,
        );
    }

    public function reactivate(User $staff, ?User $actor = null): void
    {
        if ($staff->is_active) {
            return;
        }

        $staff->forceFill(['is_active' => true])->save();

        $this->audit->log(
            action: 'staff_reactivated',
            subject: $staff,
            oldValue: ['is_active' => false],
            newValue: ['is_active' => true],
            actor: $actor ?? auth()->user(),
        );
    }

    /**
     * There must always be somebody who can work this screen.
     *
     * An Owner is the only role that can manage staff, so demoting or
     * deactivating the last active one locks every remaining person out of
     * ever granting a role again — and the way back is the tinker session this
     * class was built to retire.
     *
     * Only an account that already *is* an Owner can be the one losing that,
     * which is the whole condition. Promotion never reaches the query — a Sales
     * account becoming an Owner fails the first line and returns — and neither
     * does an Owner "changed" to Owner, because changeRole() returns on the
     * role being unchanged before this is called.
     */
    private function refuseLastOwner(User $staff): void
    {
        if ($staff->role !== Role::Owner) {
            return;
        }

        $lain = User::query()
            ->where('role', Role::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($staff->getKey())
            ->exists();

        if (! $lain) {
            throw new RuntimeException(
                'Ini satu-satunya pemilik yang aktif. Angkat pemilik lain dulu, '
                .'karena tanpa pemilik aktif tidak ada yang bisa mengatur akun staf lagi.'
            );
        }
    }

    /**
     * Two different protections wear this one guard.
     *
     * The obvious one is the accident: an Owner mid-session removing their own
     * access and having no way back. The other is self-dealing — every role
     * boundary in this system separates a pair of duties, and the cheapest way
     * around all of them at once is to grant yourself the other half. Both are
     * closed by the same rule, which is that changes to your own authority are
     * somebody else's to make.
     */
    private function refuseSelf(User $staff, ?User $actor, string $pesan): void
    {
        if ($actor !== null && $actor->getKey() === $staff->getKey()) {
            throw new RuntimeException($pesan);
        }
    }

    private function endSessions(User $staff): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $staff->getKey())
            ->delete();
    }
}
