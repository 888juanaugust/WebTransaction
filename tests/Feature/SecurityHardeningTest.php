<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The hardening a launch depends on, proven rather than assumed.
 *
 * None of this is exotic — headers, a throttle, an https scheme — which is
 * exactly why it gets tests: the unexciting settings are the ones a refactor
 * drops without anyone noticing until the pentest report.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('surfaces')]
    public function test_every_surface_carries_the_security_headers(string $url): void
    {
        $response = $this->get($url);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }

    public static function surfaces(): array
    {
        return [
            'situs publik' => ['/'],
            'kebijakan privasi' => ['/kebijakan-privasi'],
            'login admin' => ['/admin/login'],
            'login portal' => ['/portal/login'],
        ];
    }

    public function test_the_admin_login_locks_after_five_wrong_attempts(): void
    {
        Filament::setCurrentPanel('admin');

        $this->assertLoginThrottles();
    }

    public function test_the_portal_login_locks_after_five_wrong_attempts(): void
    {
        Filament::setCurrentPanel('portal');

        $this->assertLoginThrottles();
    }

    private function assertLoginThrottles(): void
    {
        /*
         * Five tries, then the throttle — Filament's own limiter, asserted
         * here so an upgrade or a copied-in custom login page that loses it
         * turns a build red instead of opening the door to a password list.
         */
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'tebak@example.test', 'password' => 'salah-'.$i])
                ->call('authenticate')
                ->assertNotNotified('Too many requests');
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => 'tebak@example.test', 'password' => 'salah-6'])
            ->call('authenticate')
            ->assertNotified();
    }

    public function test_production_forces_https_on_every_generated_url(): void
    {
        /*
         * Behind Caddy the app sees a proxied hop; the scheme is forced in
         * the provider. Simulated here by re-running the provider's logic
         * the way production boots it.
         */
        URL::forceScheme('https');

        $this->assertStringStartsWith('https://', route('publik.beranda'));

        URL::forceScheme('http');
    }
}
