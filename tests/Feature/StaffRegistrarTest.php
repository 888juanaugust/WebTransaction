<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Hiring, role changes, lockouts and leavers.
 *
 * The properties worth holding are all about what must remain true afterwards:
 * somebody can still administer the system, nobody widened their own authority,
 * and every one of those movements left a record. The happy path — a new
 * account exists and can log in — is the least interesting thing here.
 */
class StaffRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private StaffRegistrar $registrar;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = app(StaffRegistrar::class);
        $this->owner = User::factory()->owner()->create(['name' => 'Pemilik']);
    }

    public function test_hiring_someone_creates_a_usable_active_account(): void
    {
        $staff = $this->registrar->create(
            nama: 'Rina',
            email: 'rina@example.test',
            role: Role::Finance,
            password: 'rahasia-sekali',
            actor: $this->owner,
        );

        $this->assertTrue($staff->is_active);
        $this->assertSame(Role::Finance, $staff->role);

        // Hashed on the way in, not stored as typed.
        $this->assertNotSame('rahasia-sekali', $staff->password);
        $this->assertTrue(Hash::check('rahasia-sekali', $staff->password));
    }

    public function test_hiring_is_written_to_the_audit_log(): void
    {
        $staff = $this->registrar->create('Rina', 'rina@example.test', Role::Finance, 'x', $this->owner);

        $row = AuditLog::where('action', 'staff_created')->sole();

        $this->assertSame($this->owner->id, $row->actor_id);
        $this->assertSame((string) $staff->id, $row->subject_id);
        $this->assertSame('finance', $row->new_value['role']);
    }

    public function test_a_role_change_records_both_sides_of_the_move(): void
    {
        /*
         * The point of the log entry. "Finance is now Sales" answers nothing
         * on its own six months later; "Finance was Warehouse until the owner
         * moved them on the 3rd" answers the question somebody is actually
         * asking, which is how this person came to be able to do that.
         */
        $staff = User::factory()->warehouse()->create();

        $this->registrar->changeRole($staff, Role::Sales, $this->owner, alasan: 'Pindah divisi');

        $row = AuditLog::where('action', 'staff_role_changed')->sole();

        $this->assertSame('warehouse', $row->old_value['role']);
        $this->assertSame('sales', $row->new_value['role']);
        $this->assertSame('Pindah divisi', $row->alasan);
        $this->assertSame(Role::Sales, $staff->fresh()->role);
    }

    public function test_setting_the_same_role_again_writes_nothing(): void
    {
        $staff = User::factory()->sales()->create();

        $this->registrar->changeRole($staff, Role::Sales, $this->owner);

        $this->assertSame(0, AuditLog::where('action', 'staff_role_changed')->count());
    }

    public function test_nobody_may_change_their_own_role(): void
    {
        /*
         * Every role boundary in this system separates a pair of duties, and
         * the cheapest way around all of them at once is to grant yourself the
         * other half.
         */
        $second = User::factory()->owner()->create();

        $this->expectException(RuntimeException::class);
        $this->registrar->changeRole($this->owner, Role::Sales, $this->owner);

        $this->assertSame(Role::Owner, $this->owner->fresh()->role);
        $this->assertTrue($second->exists);
    }

    public function test_the_last_active_owner_cannot_be_demoted(): void
    {
        /*
         * Owner is the only role that can work this screen, so demoting the
         * last one locks everybody out of ever granting a role again — and the
         * way back in is the tinker session this class was built to retire.
         */
        $other = User::factory()->owner()->create();

        // Two owners, so this one is not the last.
        $this->registrar->changeRole($other, Role::Finance, $this->owner);
        $this->assertSame(Role::Finance, $other->fresh()->role);

        // Now $this->owner is the last, and a third party attempting it fails
        // for the last-owner reason rather than the self-dealing one.
        $penipu = User::factory()->owner()->create(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/satu-satunya pemilik/');
        $this->registrar->changeRole($this->owner, Role::Sales, $penipu);
    }

    public function test_an_inactive_owner_does_not_count_as_cover(): void
    {
        /*
         * The check asks for another *active* owner. An owner who has left and
         * been switched off cannot log in to fix anything, so counting them
         * would be counting a person who is not there.
         */
        User::factory()->owner()->create(['is_active' => false]);
        $lain = User::factory()->finance()->create();

        $this->expectException(RuntimeException::class);
        $this->registrar->deactivate($this->owner, $lain);

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_promoting_someone_to_owner_is_never_blocked_by_the_last_owner_rule(): void
    {
        /*
         * The guard fires on an account that *is* an Owner losing that. An
         * account arriving at Owner adds cover rather than removing it, and an
         * off-by-one here would make the second owner impossible to appoint —
         * which is the state the guard exists to let you escape.
         */
        $staff = User::factory()->sales()->create();

        $this->registrar->changeRole($staff, Role::Owner, $this->owner);

        $this->assertSame(Role::Owner, $staff->fresh()->role);
    }

    public function test_a_leaver_is_switched_off_rather_than_deleted(): void
    {
        /*
         * Deleting is not on offer anywhere, and the database agrees: audit_logs
         * .actor_id references users with ON DELETE NO ACTION, so removing
         * somebody who ever did anything would either fail or take the evidence
         * of what they did with it.
         */
        $staff = User::factory()->finance()->create();

        $this->registrar->deactivate($staff, $this->owner, alasan: 'Mengundurkan diri');

        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);

        $row = AuditLog::where('action', 'staff_deactivated')->sole();
        $this->assertSame('Mengundurkan diri', $row->alasan);
    }

    public function test_a_deactivated_account_is_refused_the_panel(): void
    {
        $staff = User::factory()->finance()->create();
        $panel = Filament::getPanel('admin');

        $this->assertTrue($staff->canAccessPanel($panel));

        $this->registrar->deactivate($staff, $this->owner);

        $this->assertFalse($staff->fresh()->canAccessPanel($panel));
    }

    public function test_deactivating_someone_ends_the_session_they_are_sitting_in(): void
    {
        /*
         * Panel access is re-checked per request, so a live session goes dead
         * on its next page load anyway. Clearing the row makes that immediate,
         * which is the difference that matters on the one occasion this gets
         * used in anger.
         */
        /*
         * The suite runs on the array driver, so the config is set here rather
         * than skipped. Skipping would mean this branch never runs anywhere,
         * and production is the one place it does.
         */
        config(['session.driver' => 'database', 'session.table' => 'sessions']);

        $staff = User::factory()->finance()->create();

        DB::table('sessions')->insert([
            'id' => 'sesi-yang-sedang-terbuka',
            'user_id' => $staff->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->registrar->deactivate($staff, $this->owner);

        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-yang-sedang-terbuka']);
    }

    public function test_deactivating_works_when_sessions_are_not_in_the_database(): void
    {
        /*
         * Redis or cookie sessions have no table to clear, and reaching for one
         * would turn a routine deactivation into an error — failing shut on the
         * one action whose whole purpose is to succeed quickly.
         */
        config(['session.driver' => 'array']);

        $staff = User::factory()->finance()->create();

        $this->registrar->deactivate($staff, $this->owner);

        $this->assertFalse($staff->fresh()->is_active);
    }

    public function test_nobody_may_deactivate_their_own_account(): void
    {
        User::factory()->owner()->create();

        $this->expectException(RuntimeException::class);
        $this->registrar->deactivate($this->owner, $this->owner);

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_deactivating_someone_already_off_writes_nothing(): void
    {
        $staff = User::factory()->finance()->create(['is_active' => false]);

        $this->registrar->deactivate($staff, $this->owner);

        $this->assertSame(0, AuditLog::where('action', 'staff_deactivated')->count());
    }

    public function test_somebody_locked_out_can_be_let_back_in(): void
    {
        $staff = User::factory()->finance()->create(['is_active' => false]);

        $this->registrar->reactivate($staff, $this->owner);

        $this->assertTrue($staff->fresh()->is_active);
        $this->assertSame(
            $this->owner->id,
            AuditLog::where('action', 'staff_reactivated')->sole()->actor_id,
        );
    }

    public function test_an_administrative_password_reset_never_records_the_password(): void
    {
        /*
         * The audit log is readable by the Owner, so a password written into
         * it would be a password published to exactly the audience that is not
         * supposed to keep holding it.
         */
        $staff = User::factory()->finance()->create();

        $this->registrar->setPassword($staff, 'sandi-baru-yang-panjang', $this->owner);

        $this->assertTrue(Hash::check('sandi-baru-yang-panjang', $staff->fresh()->password));

        $row = AuditLog::where('action', 'staff_password_reset')->sole();
        $this->assertStringNotContainsString(
            'sandi-baru-yang-panjang',
            json_encode([$row->old_value, $row->new_value, $row->alasan]),
        );
    }

    public function test_a_password_reset_invalidates_a_remember_me_cookie(): void
    {
        /*
         * Otherwise whoever the reset was defending against strolls back in on
         * the cookie they already have, and the reset reset nothing.
         */
        $staff = User::factory()->finance()->create(['remember_token' => 'token-lama']);

        $this->registrar->setPassword($staff, 'sandi-baru', $this->owner);

        $this->assertNotSame('token-lama', $staff->fresh()->remember_token);
    }

    public function test_a_rename_is_logged_separately_from_a_role_change(): void
    {
        $staff = User::factory()->sales()->create(['name' => 'Budi', 'email' => 'budi@example.test']);

        $this->registrar->rename($staff, 'Budi Santoso', 'budi.santoso@example.test', $this->owner);

        $row = AuditLog::where('action', 'staff_renamed')->sole();

        $this->assertSame('Budi', $row->old_value['name']);
        $this->assertSame('budi.santoso@example.test', $row->new_value['email']);
        $this->assertSame(0, AuditLog::where('action', 'staff_role_changed')->count());
    }

    public function test_a_rename_that_changes_nothing_writes_nothing(): void
    {
        $staff = User::factory()->sales()->create(['name' => 'Budi', 'email' => 'budi@example.test']);

        $this->registrar->rename($staff, 'Budi', 'budi@example.test', $this->owner);

        $this->assertSame(0, AuditLog::where('action', 'staff_renamed')->count());
    }

    public function test_only_the_owner_may_manage_staff(): void
    {
        foreach ([Role::Sales, Role::Warehouse, Role::Finance] as $role) {
            $this->assertFalse($role->canManageStaff(), $role->value.' must not manage staff');
        }

        $this->assertTrue(Role::Owner->canManageStaff());
    }
}
