<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\VirtualAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The go-live check.
 *
 * What is worth testing here is not the happy path — that is what the command
 * itself is for, run on the real server. It is the decisions the command makes
 * before it touches anything: which gateway is really bound, whether these are
 * production keys, and whether it is willing to post a fabricated event at a
 * system that is taking real money.
 */
class XenditVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::factory()->creditLimit(50_000_000)->create([
            'nama' => 'Bengkel Jaya Motor',
            'status' => Company::STATUS_ACTIVE,
        ]);

        config(['app.url' => 'https://portal.example.test']);
    }

    public function test_without_a_key_it_fails_and_names_what_is_actually_happening(): void
    {
        /*
         * The dangerous part of a missing key is not an error — it is that
         * everything keeps working. LocalVirtualAccountGateway mints account
         * numbers that look entirely plausible and that no bank has heard of.
         */
        config(['xendit.secret_key' => null]);

        $this->artisan('xendit:verify')
            ->expectsOutputToContain('XENDIT_SECRET_KEY kosong')
            ->expectsOutputToContain('LocalVirtualAccountGateway')
            ->assertExitCode(1);
    }

    public function test_sandbox_keys_pass_the_configuration_and_api_stages(): void
    {
        $this->sandbox();

        Http::fake([
            'api.xendit.co/*' => Http::response([
                'id' => '5f9a3f2e1c',
                'external_id' => 'company-1-BCA',
                'account_number' => '9881234567890',
            ]),
        ]);

        $this->artisan('xendit:verify', ['--skip-callback' => true])
            ->expectsOutputToContain('mode: sandbox')
            ->expectsOutputToContain('XenditVirtualAccountGateway')
            ->expectsOutputToContain('9881234567890')
            ->assertExitCode(0);
    }

    public function test_a_key_of_neither_shape_is_reported_rather_than_assumed(): void
    {
        // A key that is neither prefix is usually a truncated paste, and
        // guessing which environment it belongs to is how a sandbox key ends
        // up live — or worse, the reverse.
        config(['xendit.secret_key' => 'some-key-from-somewhere', 'xendit.callback_token' => 'tok']);

        Http::fake(['api.xendit.co/*' => Http::response(['account_number' => '99', 'id' => 'x'])]);

        $this->artisan('xendit:verify', ['--skip-callback' => true])
            ->expectsOutputToContain('tidak dikenali')
            ->assertExitCode(0);
    }

    public function test_it_refuses_to_post_a_fabricated_event_at_production_keys(): void
    {
        /*
         * The event id is the idempotency key for real money. Writing an
         * invented one into webhook_events on a live system puts a row into
         * the table that decides what has already been handled.
         */
        config([
            'xendit.secret_key' => 'xnd_production_abc123',
            'xendit.callback_token' => 'tok',
        ]);

        Http::fake([
            'api.xendit.co/*' => Http::response(['account_number' => '99', 'id' => 'x']),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('xendit:verify')
            ->expectsOutputToContain('PRODUKSI')
            ->expectsOutputToContain('tidak boleh disuntik event palsu')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/webhooks/xendit'));
    }

    public function test_a_rejected_key_is_reported_against_the_api_stage(): void
    {
        $this->sandbox();

        Http::fake(['api.xendit.co/*' => Http::response(['message' => 'invalid api key'], 401)]);

        $this->artisan('xendit:verify', ['--skip-callback' => true])
            ->expectsOutputToContain('sandbox/produksi')
            ->assertExitCode(1);
    }

    public function test_a_refused_callback_token_says_which_two_things_to_compare(): void
    {
        /*
         * A stripped x-callback-token header and a mismatched one are the same
         * 401, and both are silent in production: the money still arrives, the
         * order never goes to paid.
         */
        $this->sandbox();

        Http::fake([
            'api.xendit.co/*' => Http::response(['account_number' => '99', 'id' => 'x']),
            'portal.example.test/*' => Http::response(['message' => 'Invalid callback token.'], 401),
        ]);

        $this->artisan('xendit:verify')
            ->expectsOutputToContain('token ditolak')
            ->expectsOutputToContain('dashboard Xendit')
            ->assertExitCode(1);
    }

    public function test_an_answered_callback_that_was_never_stored_is_a_failure(): void
    {
        /*
         * A 200 from something that is not this application — a CDN, a holding
         * page, a proxy answering on its behalf. Looks perfect from Xendit's
         * side and no payment will ever be recorded.
         */
        $this->sandbox();

        Http::fake([
            'api.xendit.co/*' => Http::response(['account_number' => '99', 'id' => 'x']),
            'portal.example.test/*' => Http::response(['message' => 'Received.'], 200),
        ]);

        $this->artisan('xendit:verify')
            ->expectsOutputToContain('tidak tersimpan di webhook_events')
            ->assertExitCode(1);
    }

    public function test_an_untested_api_call_is_not_reported_as_a_pass(): void
    {
        /*
         * ensureFor() returns the stored row without touching the network, so
         * a company that already has an account proves nothing about the keys.
         * Saying "API ✓" there would be the worst kind of green: it claims the
         * credentials work when they were never asked.
         */
        $this->sandbox();

        VirtualAccount::query()->create([
            'company_id' => Company::query()->sole()->id,
            'bank_code' => 'BCA',
            'account_number' => '8801111111111',
            'external_id' => 'company-1-BCA',
            'status' => 'active',
        ]);

        Http::fake(['api.xendit.co/*' => Http::response(['account_number' => '99', 'id' => 'x'])]);

        $this->artisan('xendit:verify', ['--skip-callback' => true])
            ->expectsOutputToContain('tidak ada panggilan yang dites')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_a_locally_minted_account_surviving_into_production_is_a_failure(): void
    {
        /*
         * The one failure mode nothing else in the system can see. The row is
         * perfectly well-formed from our side; it is the bank that has never
         * heard of the number, and only once a customer has tried to pay.
         */
        $this->sandbox();

        VirtualAccount::query()->create([
            'company_id' => Company::query()->sole()->id,
            'bank_code' => 'BCA',
            'account_number' => '8808000000001',
            'external_id' => 'local-PLG-0001-BCA',
            'status' => 'active',
        ]);

        Http::fake(['api.xendit.co/*' => Http::response(['account_number' => '99', 'id' => 'x'])]);

        $this->artisan('xendit:verify', ['--skip-callback' => true])
            ->expectsOutputToContain('dibuat sendiri, bukan oleh Xendit')
            ->expectsOutputToContain('8808000000001')
            ->assertExitCode(1);
    }

    private function sandbox(): void
    {
        config([
            'xendit.secret_key' => 'xnd_development_abc123',
            'xendit.callback_token' => 'tok',
        ]);
    }
}
