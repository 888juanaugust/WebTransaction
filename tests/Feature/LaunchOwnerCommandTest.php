<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The bootstrap door: open exactly until the first active Owner exists,
 * then shut for good — after that, staff accounts are the Staf screen's
 * business, audited to a person.
 */
class LaunchOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_owner_with_an_audit_trail(): void
    {
        $this->artisan('launch:owner', ['--nama' => 'Ibu Pemilik', '--email' => 'pemilik@javaindo.example'])
            ->expectsQuestion('Kata sandi (min. 12 karakter)', 'sangat-rahasia-99')
            ->expectsQuestion('Ulangi kata sandi', 'sangat-rahasia-99')
            ->assertSuccessful();

        $owner = User::query()->firstWhere('email', 'pemilik@javaindo.example');

        $this->assertSame(Role::Owner, $owner->role);
        $this->assertTrue($owner->is_active);
        // Every region: the Owner's posture is no region at all.
        $this->assertNull($owner->region_id);
        $this->assertTrue(Hash::check('sangat-rahasia-99', $owner->password));

        // Born with a birth certificate, even with no actor to sign it.
        $audit = AuditLog::query()->where('action', 'staff_created')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('pemilik@javaindo.example', $audit->new_value['email'] ?? null);
    }

    public function test_it_refuses_once_an_active_owner_exists(): void
    {
        User::factory()->owner()->create();

        $this->artisan('launch:owner', ['--nama' => 'Penyusup', '--email' => 'lagi@javaindo.example'])
            ->expectsOutputToContain('Sudah ada Pemilik aktif')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'lagi@javaindo.example']);
    }

    /**
     * A deactivated Owner is not an Owner who can let anyone in. The door
     * reopens, because the alternative is the tinker session this command
     * exists to retire.
     */
    public function test_a_deactivated_owner_does_not_block_the_door(): void
    {
        User::factory()->owner()->create(['is_active' => false]);

        $this->artisan('launch:owner', ['--nama' => 'Pemilik Baru', '--email' => 'baru@javaindo.example'])
            ->expectsQuestion('Kata sandi (min. 12 karakter)', 'sangat-rahasia-99')
            ->expectsQuestion('Ulangi kata sandi', 'sangat-rahasia-99')
            ->assertSuccessful();
    }

    public function test_it_refuses_a_short_password_and_a_mismatch(): void
    {
        $this->artisan('launch:owner', ['--nama' => 'Ibu Pemilik', '--email' => 'pemilik@javaindo.example'])
            ->expectsQuestion('Kata sandi (min. 12 karakter)', 'pendek')
            ->expectsOutputToContain('minimal 12 karakter')
            ->assertFailed();

        $this->artisan('launch:owner', ['--nama' => 'Ibu Pemilik', '--email' => 'pemilik@javaindo.example'])
            ->expectsQuestion('Kata sandi (min. 12 karakter)', 'sangat-rahasia-99')
            ->expectsQuestion('Ulangi kata sandi', 'beda-sama-sekali-99')
            ->expectsOutputToContain('tidak sama')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'pemilik@javaindo.example']);
    }

    public function test_it_refuses_a_bad_or_taken_email(): void
    {
        $this->artisan('launch:owner', ['--nama' => 'Ibu Pemilik', '--email' => 'bukan-email'])
            ->assertFailed();

        User::factory()->sales()->create(['email' => 'dipakai@javaindo.example', 'is_active' => true]);

        $this->artisan('launch:owner', ['--nama' => 'Ibu Pemilik', '--email' => 'dipakai@javaindo.example'])
            ->assertFailed();
    }
}
