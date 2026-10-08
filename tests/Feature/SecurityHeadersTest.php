<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Tests\TestCase;

/** Every response carries the security headers; behind the configured proxies the visitor's own address is seen. */
class SecurityHeadersTest extends TestCase
{
    public function test_responses_carry_the_security_headers(): void
    {
        $response = $this->get('/admin/login')->assertOk();
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::POLICY);
        $this->assertStringContainsString("frame-ancestors 'self'", SecurityHeaders::POLICY);
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/admin/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_configured_proxies_are_trusted(): void
    {
        config(['app.trusted_proxies' => '10.0.0.0/8']);
        $this->app['router']->get('/_ip', fn () => request()->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/_ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('203.0.113.9');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/_ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('198.51.100.7');
    }
}
