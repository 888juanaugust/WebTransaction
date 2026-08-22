<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Launch\AttestationRecorder;
use App\Domain\Launch\LaunchCheck;
use App\Domain\Launch\LaunchCheckKind;
use App\Domain\Launch\LaunchReadiness;
use App\Domain\Orders\OrderStatus;
use App\Models\BackupRun;
use App\Models\Invoice;
use App\Models\LaunchAttestation;
use App\Models\Order;
use App\Models\PriceListVersion;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Is this thing ready to be used by a real business?
 *
 * The property worth holding down is the separation. A checked item must never
 * be markable by a person — that is what stops a checklist becoming a row of
 * boxes somebody ticked on a Friday. An attested item must never be decided by
 * the system — there is nothing to decide it from.
 */
class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    // --- the checks the system makes for itself -----------------------------

    public function test_a_fresh_install_fails_almost_everything(): void
    {
        /*
         * The useful default. A system that opened on a clean checklist would
         * be telling somebody they are ready to trade before a single figure
         * has been entered.
         */
        $readiness = app(LaunchReadiness::class);

        $this->assertGreaterThan(10, $readiness->outstanding());
        $this->assertFalse($readiness->isReady());
    }

    public function test_the_tax_identity_check_reads_the_config(): void
    {
        $this->assertFalse($this->check('identitas_pajak')->lulus);

        config(['pajak.penjual' => ['npwp' => '01.234.567.8-901.000', 'nama' => 'PT Java Indo']]);

        $this->assertTrue($this->check('identitas_pajak')->lulus);
    }

    public function test_it_names_which_tax_field_is_missing(): void
    {
        // "Something is wrong" is not an actionable finding.
        config(['pajak.penjual' => ['npwp' => '01.234.567.8-901.000', 'nama' => null]]);

        $this->assertStringContainsString('nama', (string) $this->check('identitas_pajak')->temuan);
    }

    public function test_placeholder_contact_details_count_as_missing(): void
    {
        /*
         * The shipped defaults are plausible-looking, which is exactly the
         * problem: an address of "Jl. Contoh No. 1" on a site that has been
         * through PSE registration is worse than a blank one.
         */
        config(['perusahaan.legal' => ['nib' => '123', 'npwp' => '456']]);
        config(['perusahaan.kontak' => [
            'alamat' => 'Jl. Contoh No. 1, Jakarta, Indonesia',
            'telepon' => '+62 21 5555 1234',
            'whatsapp' => '+62 811 2233 4455',
            'email' => 'sales@javaindo.co.id',
        ]]);

        $check = $this->check('identitas_perusahaan');

        $this->assertFalse($check->lulus);
        $this->assertStringContainsString('alamat', (string) $check->temuan);
    }

    public function test_real_contact_details_pass(): void
    {
        config(['perusahaan.legal' => ['nib' => '123', 'npwp' => '456']]);
        config(['perusahaan.kontak' => [
            'alamat' => 'Jl. Raya Bekasi KM 25, Jakarta Timur',
            'telepon' => '+62 21 5555 1234',
            'whatsapp' => '+62 811 2233 4455',
            'email' => 'sales@javaindo.co.id',
        ]]);

        $this->assertTrue($this->check('identitas_perusahaan')->lulus);
    }

    public function test_invented_partners_are_a_blocker(): void
    {
        // Naming a company as a partner in public is a claim about a real
        // business relationship, and the shipped names are made up.
        $this->assertFalse($this->check('mitra_bukan_contoh')->lulus);

        config(['perusahaan.mitra' => [['nama' => 'PT Mitra Sungguhan']]]);

        $this->assertTrue($this->check('mitra_bukan_contoh')->lulus);
    }

    public function test_no_partners_at_all_is_fine(): void
    {
        // Not listing partners is a legitimate choice. Only invented ones are
        // a problem.
        config(['perusahaan.mitra' => []]);

        $this->assertTrue($this->check('mitra_bukan_contoh')->lulus);
    }

    public function test_a_published_price_list_is_required(): void
    {
        /*
         * `migrate --seed` ships no prices on purpose, so a fresh install
         * cannot price a single order. Worth saying on the checklist rather
         * than discovering on the first morning.
         */
        $this->assertFalse($this->check('daftar_harga')->lulus);

        PriceListVersion::factory()->create(['published_at' => now()]);

        $this->assertTrue($this->check('daftar_harga')->lulus);
    }

    public function test_an_unpublished_price_list_does_not_count(): void
    {
        PriceListVersion::factory()->create(['published_at' => null, 'status' => 'draft']);

        $this->assertFalse($this->check('daftar_harga')->lulus);
    }

    public function test_a_development_xendit_key_fails_even_though_it_is_set(): void
    {
        /*
         * The nastiest of these to catch by eye. A development key looks
         * completely fine — the panel works, orders reach awaiting_payment,
         * virtual accounts appear — right up until the first invoice is never
         * paid, because none of those accounts exists at a bank.
         */
        config([
            'xendit.secret_key' => 'xnd_development_abc123',
            'xendit.callback_token' => 'tok',
        ]);

        $check = $this->check('xendit');

        $this->assertFalse($check->lulus);
        $this->assertStringContainsString('development', (string) $check->temuan);
    }

    public function test_a_production_key_with_a_callback_token_passes(): void
    {
        config([
            'xendit.secret_key' => 'xnd_production_abc123',
            'xendit.callback_token' => 'tok',
        ]);

        $this->assertTrue($this->check('xendit')->lulus);
    }

    public function test_a_missing_callback_token_fails(): void
    {
        // Without it the webhook cannot be verified, and `paid` is set only by
        // the webhook.
        config(['xendit.secret_key' => 'xnd_production_abc123', 'xendit.callback_token' => null]);

        $this->assertFalse($this->check('xendit')->lulus);
    }

    public function test_it_finds_staff_still_using_the_seeded_password(): void
    {
        $stale = User::factory()->role(Role::Finance)->create([
            'email' => 'finance@example.test',
            'password' => Hash::make('password'),
        ]);

        $check = $this->check('sandi_staf');

        $this->assertFalse($check->lulus);
        $this->assertStringContainsString($stale->email, (string) $check->temuan);
    }

    public function test_changed_passwords_pass(): void
    {
        User::query()->update(['password' => Hash::make('something-else-entirely')]);

        $this->assertTrue($this->check('sandi_staf')->lulus);
    }

    public function test_a_backup_that_never_left_the_server_does_not_count(): void
    {
        /*
         * A backup on the machine it protects is a copy, not a backup — it
         * dies with the disk it was meant to survive.
         */
        BackupRun::factory()->create([
            'status' => BackupRun::STATUS_VERIFIED,
            'offsite' => false,
            'finished_at' => now(),
        ]);

        $this->assertFalse($this->check('cadangan')->lulus);
    }

    public function test_a_verified_offsite_backup_passes(): void
    {
        BackupRun::factory()->create([
            'status' => BackupRun::STATUS_VERIFIED,
            'offsite' => true,
            'finished_at' => now(),
        ]);

        $this->assertTrue($this->check('cadangan')->lulus);
    }

    public function test_a_failed_backup_does_not_count_however_far_away_it_went(): void
    {
        BackupRun::factory()->create([
            'status' => BackupRun::STATUS_FAILED,
            'offsite' => true,
            'finished_at' => now(),
        ]);

        $this->assertFalse($this->check('cadangan')->lulus);
    }

    public function test_one_completed_order_is_the_proof_the_chain_works(): void
    {
        $this->assertFalse($this->check('order_nyata')->lulus);

        Order::factory()->create(['status' => OrderStatus::Completed]);

        $this->assertTrue($this->check('order_nyata')->lulus);
    }

    public function test_an_order_that_stopped_short_does_not_count(): void
    {
        Order::factory()->create(['status' => OrderStatus::Shipped]);

        $this->assertFalse($this->check('order_nyata')->lulus);
    }

    public function test_control_accounts_tie_on_an_empty_system(): void
    {
        // Nothing has been posted, so everything is nil and nil agrees with
        // nil. On its own this proves very little — see the test below.
        $this->assertTrue($this->check('akun_kontrol')->lulus);
    }

    public function test_a_drifted_control_account_blocks_the_launch(): void
    {
        /*
         * The test that makes the one above mean something. An invoice created
         * without its journal is precisely how a control account drifts in
         * real life — a document that exists in the subledger and never
         * reached the books.
         *
         * Going live with Piutang Usaha already adrift means never being able
         * to say whether a later difference came from before or after.
         */
        Invoice::factory()->totalling(5_000_000)->create();

        $check = $this->check('akun_kontrol');

        $this->assertFalse($check->lulus);
        $this->assertStringContainsString('Piutang Usaha', (string) $check->temuan);
    }

    // --- the ones only a person can confirm ---------------------------------

    #[DataProvider('attestableKeys')]
    public function test_an_attested_item_starts_unclaimed_and_is_claimed_by_a_person(string $kunci): void
    {
        $this->assertFalse($this->check($kunci)->lulus);

        app(AttestationRecorder::class)->attest($kunci, $this->owner, 'bukti');

        $this->assertTrue($this->check($kunci)->lulus);
    }

    public static function attestableKeys(): array
    {
        return [
            'pse' => ['pse'],
            'kbli' => ['kbli'],
            'legal ditinjau' => ['legal_ditinjau'],
            'nilai komersial' => ['nilai_komersial'],
            'format faktur' => ['format_faktur'],
            'restore dilatih' => ['restore_dilatih'],
        ];
    }

    public function test_an_attestation_records_who_said_it_and_when(): void
    {
        // The whole difference between this and a tickbox. Six months later
        // there has to be somebody to ask.
        app(AttestationRecorder::class)->attest('pse', $this->owner, 'PB-UMKU 9123456789');

        $check = $this->check('pse');

        $this->assertStringContainsString($this->owner->name, (string) $check->temuan);
        $this->assertStringContainsString('PB-UMKU 9123456789', (string) $check->temuan);
    }

    public function test_a_checked_item_can_never_be_attested(): void
    {
        /*
         * The rule that makes the screen worth having. Without it, somebody
         * could mark the tax NPWP done while it is still empty, and the
         * checklist would go green over a system that cannot issue a faktur.
         */
        $this->expectException(DomainException::class);

        app(AttestationRecorder::class)->attest('identitas_pajak', $this->owner, 'sudah kok');
    }

    public function test_an_unknown_key_is_refused(): void
    {
        // Otherwise it creates a row no checklist item reads: a tick nobody
        // sees, sitting in the table looking like work that was done.
        $this->expectException(DomainException::class);

        app(AttestationRecorder::class)->attest('tidak_ada_item_ini', $this->owner, 'x');
    }

    #[DataProvider('rolesWhoMayNot')]
    public function test_only_the_owner_may_attest(Role $role): void
    {
        $this->expectException(DomainException::class);

        app(AttestationRecorder::class)->attest(
            'pse', User::factory()->role($role)->create(), 'bukti',
        );
    }

    public static function rolesWhoMayNot(): array
    {
        return [
            'keuangan' => [Role::Finance],
            'sales' => [Role::Sales],
            'gudang' => [Role::Warehouse],
        ];
    }

    public function test_re_attesting_refreshes_rather_than_duplicating(): void
    {
        // A lawyer re-reads the terms after a change; a registration is
        // renewed. Refusing would push people into retract-then-attest.
        $recorder = app(AttestationRecorder::class);

        $recorder->attest('legal_ditinjau', $this->owner, 'ditinjau Juli');
        $recorder->attest('legal_ditinjau', $this->owner, 'ditinjau ulang September');

        $this->assertSame(1, LaunchAttestation::query()->where('kunci', 'legal_ditinjau')->count());
        $this->assertStringContainsString('September', (string) $this->check('legal_ditinjau')->temuan);
    }

    public function test_retracting_puts_the_item_back_and_needs_a_reason(): void
    {
        $recorder = app(AttestationRecorder::class);
        $recorder->attest('pse', $this->owner, 'PB-UMKU 9123456789');

        $recorder->retract('pse', $this->owner, 'Ternyata belum terbit');

        $this->assertFalse($this->check('pse')->lulus);
        $this->assertSame(0, LaunchAttestation::query()->count());
    }

    public function test_a_retraction_must_say_why(): void
    {
        app(AttestationRecorder::class)->attest('pse', $this->owner, 'bukti');

        $this->expectException(DomainException::class);
        app(AttestationRecorder::class)->retract('pse', $this->owner, '   ');
    }

    public function test_retracting_something_never_claimed_is_refused(): void
    {
        $this->expectException(DomainException::class);
        app(AttestationRecorder::class)->retract('pse', $this->owner, 'alasan');
    }

    // --- the shape of the list ----------------------------------------------

    public function test_every_key_is_unique(): void
    {
        // Two items sharing a key would let attesting one silently mark the
        // other, which is the failure mode this whole screen exists to avoid.
        $keys = array_map(fn (LaunchCheck $c) => $c->kunci, app(LaunchReadiness::class)->checks());

        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_checked_items_are_never_attestable(): void
    {
        foreach (app(LaunchReadiness::class)->checks() as $check) {
            $this->assertSame(
                $check->jenis === LaunchCheckKind::Pernyataan,
                $check->isAttestable(),
                "{$check->kunci} disagrees with itself about whether it can be attested.",
            );
        }
    }

    public function test_every_failing_checked_item_says_what_to_do_about_it(): void
    {
        // A red row with no next step is a complaint, not a checklist.
        foreach (app(LaunchReadiness::class)->checks() as $check) {
            if ($check->jenis === LaunchCheckKind::Otomatis && ! $check->lulus) {
                $this->assertNotNull($check->tindakan, "{$check->kunci} has no action.");
            }
        }
    }

    /**
     * The list is memoised per request, and a test changes the world between
     * two looks at it in a way a real request never does — config is settled
     * at boot. So each look starts fresh.
     */
    private function check(string $kunci): LaunchCheck
    {
        $readiness = app(LaunchReadiness::class);
        $readiness->forget();

        foreach ($readiness->checks() as $check) {
            if ($check->kunci === $kunci) {
                return $check;
            }
        }

        $this->fail("No launch check named '{$kunci}'.");
    }
}
