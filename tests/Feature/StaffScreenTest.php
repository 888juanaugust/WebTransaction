<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The screen that grants every other permission in the system.
 *
 * Its access control is worth exactly as much as all the role separations put
 * together, because this is the one page where a person can be handed the
 * other half of any pair of duties. So the first thing tested is who can open
 * it, and the rest is that nothing here can be used to widen your own
 * authority or to leave nobody holding it.
 */
class StaffScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Pemilik']);
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
            'keuangan' => [Role::Finance, false],
            'pemilik' => [Role::Owner, true],
        ];
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, StaffResource::canViewAny());
        $this->assertSame($allowed, StaffResource::canCreate());
    }

    #[DataProvider('roles')]
    public function test_the_navigation_entry_follows_the_same_rule(Role $role, bool $allowed): void
    {
        /*
         * A link that 403s is still a link. Somebody in Finance should not be
         * looking at a "Staf" item in their sidebar and wondering.
         *
         * Asserted against the navigation the panel actually builds, not
         * against shouldRegisterNavigation() — that flag is true for every
         * role, because the authorisation check runs when the menu is
         * assembled rather than on the resource's own registration.
         */
        $this->actingAs(User::factory()->role($role)->create());

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $labels = collect($panel->getNavigation())
            ->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()));

        $this->assertSame($allowed, $labels->contains('Staf'), $role->value.' navigation');
    }

    public function test_a_non_owner_is_refused_the_page_itself(): void
    {
        /*
         * Not just the button. canViewAny() gates the navigation and the
         * resource's own authorisation; this asserts the HTTP route refuses
         * somebody who types the URL.
         */
        $this->actingAs(User::factory()->finance()->create());

        $this->get(StaffResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_list_shows_inactive_staff_rather_than_hiding_them(): void
    {
        /*
         * A leaver's row is the evidence they were shut off. Filtering them
         * out by default answers "can Budi still log in?" with silence.
         */
        $keluar = User::factory()->sales()->create(['name' => 'Budi', 'is_active' => false]);

        Livewire::actingAs($this->owner)
            ->test(ListStaff::class)
            ->assertCanSeeTableRecords([$this->owner, $keluar]);
    }

    public function test_hiring_someone_from_the_form_goes_through_the_registrar(): void
    {
        Livewire::actingAs($this->owner)
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'Rina',
                'email' => 'rina@example.test',
                'role' => Role::Finance->value,
                'region_id' => $this->currentRegion()->id,
                'password' => 'sandi-awal-panjang',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = User::where('email', 'rina@example.test')->sole();

        $this->assertSame(Role::Finance, $staff->role);
        $this->assertTrue($staff->is_active);
        $this->assertTrue(Hash::check('sandi-awal-panjang', $staff->password));

        // The registrar is the only path that writes this.
        $this->assertSame(
            $this->owner->id,
            AuditLog::where('action', 'staff_created')->sole()->actor_id,
        );
    }

    public function test_a_short_initial_password_is_refused_by_the_form(): void
    {
        Livewire::actingAs($this->owner)
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'Rina',
                'email' => 'rina@example.test',
                'role' => Role::Sales->value,
                'password' => 'pendek',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'rina@example.test']);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        User::factory()->sales()->create(['email' => 'ada@example.test']);

        Livewire::actingAs($this->owner)
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'Kembar',
                'email' => 'ada@example.test',
                'role' => Role::Sales->value,
                'password' => 'sandi-awal-panjang',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_editing_writes_a_rename_and_a_role_change_as_separate_entries(): void
    {
        $staff = User::factory()->warehouse()->create([
            'name' => 'Budi',
            'email' => 'budi@example.test',
        ]);

        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $staff->getKey()])
            ->fillForm([
                'name' => 'Budi Santoso',
                'email' => 'budi@example.test',
                'role' => Role::Sales->value,
                'region_id' => $this->currentRegion()->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Role::Sales, $staff->fresh()->role);
        $this->assertSame(1, AuditLog::where('action', 'staff_renamed')->count());
        $this->assertSame(1, AuditLog::where('action', 'staff_role_changed')->count());
    }

    public function test_the_owner_cannot_change_their_own_role_from_the_form(): void
    {
        /*
         * The select is disabled on your own row, so Filament omits it from
         * the submitted payload. That must be a no-op rather than a crash on a
         * missing key — and the role must not move.
         */
        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $this->owner->getKey()])
            ->fillForm(['name' => 'Pemilik Baru', 'email' => $this->owner->email])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Role::Owner, $this->owner->fresh()->role);
        $this->assertSame('Pemilik Baru', $this->owner->fresh()->name);
    }

    public function test_setting_a_password_from_the_screen_changes_it(): void
    {
        $staff = User::factory()->finance()->create();

        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $staff->getKey()])
            ->callAction('setelSandi', ['password' => 'sandi-baru-panjang'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('sandi-baru-panjang', $staff->fresh()->password));
        $this->assertSame(1, AuditLog::where('action', 'staff_password_reset')->count());
    }

    public function test_deactivating_from_the_screen_requires_a_reason(): void
    {
        $staff = User::factory()->finance()->create();

        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $staff->getKey()])
            ->callAction('nonaktifkan', ['alasan' => ''])
            ->assertHasActionErrors(['alasan']);

        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_deactivating_from_the_screen_switches_the_account_off(): void
    {
        $staff = User::factory()->finance()->create();

        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $staff->getKey()])
            ->callAction('nonaktifkan', ['alasan' => 'Mengundurkan diri'])
            ->assertHasNoActionErrors();

        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_the_deactivate_action_is_hidden_on_your_own_account(): void
    {
        /*
         * Hidden rather than shown-and-refused: this is the one action whose
         * accident has no way back from inside the app.
         */
        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $this->owner->getKey()])
            ->assertActionHidden('nonaktifkan');
    }

    public function test_reactivate_appears_only_for_an_account_that_is_off(): void
    {
        $aktif = User::factory()->finance()->create();
        $mati = User::factory()->finance()->create(['is_active' => false]);

        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $aktif->getKey()])
            ->assertActionHidden('aktifkanLagi');

        Livewire::actingAs($this->owner)
            ->test(EditStaff::class, ['record' => $mati->getKey()])
            ->assertActionVisible('aktifkanLagi')
            ->callAction('aktifkanLagi')
            ->assertHasNoActionErrors();

        $this->assertTrue($mati->fresh()->is_active);
    }

    public function test_nothing_on_this_resource_deletes(): void
    {
        $staff = User::factory()->finance()->create();

        $this->actingAs($this->owner);

        $this->assertFalse(StaffResource::canDelete($staff));
        $this->assertFalse(StaffResource::canDeleteAny());
    }
}
