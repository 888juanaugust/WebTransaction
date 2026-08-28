<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
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
    ): User {
        $staff = User::create([
            'name' => $nama,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
            // Null means every region — the Owner's posture. Anyone else with
            // null is pinned to the default region by the middleware, so an
            // unassigned clerk sees one region, never all of them.
            'region_id' => $role === Role::Owner ? null : $regionId,
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
