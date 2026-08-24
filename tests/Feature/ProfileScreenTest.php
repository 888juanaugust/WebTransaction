<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Launch\LaunchReadiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff changing their own password.
 *
 * Written because there was no way to. The launch checklist told people to use
 * "halaman profil masing-masing" and no such page existed, so the seeded
 * `password` was on every account including the owner's, permanently, and the
 * one check that noticed could never go green.
 */
class ProfileScreenTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('everyRole')]
    public function test_every_role_can_reach_their_own_profile(Role $role): void
    {
        // Not an Owner privilege. A warehouse account left on the seeded
        // password is the same hole as the owner's.
        $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get('/admin/profile')
            ->assertOk();
    }

    public static function everyRole(): array
    {
        return [
            'sales' => [Role::Sales],
            'gudang' => [Role::Warehouse],
            'keuangan' => [Role::Finance],
            'pemilik' => [Role::Owner],
        ];
    }

    public function test_the_route_the_launch_checklist_points_at_actually_exists(): void
    {
        /*
         * The whole reason this file exists. An instruction pointing at a page
         * that is not there is worse than no instruction: it gets followed,
         * fails, and the person assumes they misread it.
         */
        $this->assertTrue(app('router')->has('filament.admin.auth.profile'));
    }

    public function test_changing_the_password_clears_the_launch_check(): void
    {
        /*
         * End to end, through the figure that reports it. Asserting the hash
         * changed would prove the model works; this proves the checklist item
         * a person is trying to clear actually clears.
         */
        $user = User::factory()->role(Role::Owner)->create(['password' => Hash::make('password')]);
        $this->actingAs($user);

        $readiness = app(LaunchReadiness::class);

        $this->assertFalse($this->passwordCheck($readiness)->lulus);

        $user->forceFill(['password' => Hash::make('a-real-one')])->save();
        $readiness->forget();

        $this->assertTrue($this->passwordCheck($readiness)->lulus);
    }

    private function passwordCheck(LaunchReadiness $readiness): object
    {
        foreach ($readiness->checks() as $check) {
            if ($check->kunci === 'sandi_staf') {
                return $check;
            }
        }

        $this->fail('No sandi_staf check.');
    }
}
