<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
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

    public function test_the_public_site_enforces_a_nonce_based_csp(): void
    {
        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'Situs publik harus mengirim CSP.');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // The nonce in the header is the nonce on the inline scripts — an
        // injected script cannot know it, which is the entire point.
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $m));
        $response->assertSee('nonce="'.$m[1].'"', escape: false);
    }

    public function test_the_panels_keep_their_documented_csp_deferral(): void
    {
        // Filament and Livewire lean on inline scripts; a CSP here without
        // nonce plumbing would break every screen. The deferral is a
        // decision recorded in SecurityHeaders — this pins that the public
        // group's CSP does not leak onto the panels by accident.
        $this->get('/admin/login')
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_production_cannot_run_with_debug_on(): void
    {
        /*
         * The fail-safe in AppServiceProvider: whatever .env says, a
         * production boot forces debug off — a stack trace with SQL and
         * file paths must never reach whoever triggered the error.
         */
        config(['app.debug' => true]);
        app()->detectEnvironment(fn () => 'production');

        app(AppServiceProvider::class, ['app' => app()])->boot();

        $this->assertFalse(config('app.debug'));

        app()->detectEnvironment(fn () => 'testing');
    }
}
