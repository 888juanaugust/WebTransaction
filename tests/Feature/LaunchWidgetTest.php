<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Launch\AttestationRecorder;
use App\Domain\Launch\LaunchCheckKind;
use App\Domain\Launch\LaunchReadiness;
use App\Domain\Orders\OrderStatus;
use App\Filament\Widgets\LaunchReadinessSummary;
use App\Models\BackupRun;
use App\Models\Order;
use App\Models\PriceListVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The launch checklist on the dashboard.
 *
 * The property that matters most is the one nobody would notice for two years:
 * it has to **go away** when the list is clear. A permanent banner
 * congratulating a business that launched long ago is decoration, and
 * decoration at the top of a dashboard is what teaches people to stop reading
 * the top of it.
 */
class LaunchWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    #[DataProvider('roles')]
    public function test_only_the_owner_sees_it(Role $role, bool $visible): void
    {
        // A warning shown to somebody who cannot act on it is noise that
        // trains them to ignore warnings.
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($visible, LaunchReadinessSummary::canView());
    }

    public static function roles(): array
    {
        return [
            'pemilik' => [Role::Owner, true],
            'keuangan' => [Role::Finance, false],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_it_shows_while_anything_is_outstanding(): void
    {
        $this->actingAs($this->owner);

        $this->assertTrue(LaunchReadinessSummary::canView());
    }

    public function test_it_disappears_entirely_once_the_list_is_clear(): void
    {
        $this->actingAs($this->owner);

        $this->clearEverything();

        $this->assertTrue(app(LaunchReadiness::class)->isReady());
        $this->assertFalse(LaunchReadinessSummary::canView());
    }

    public function test_it_comes_back_if_something_breaks_again(): void
    {
        /*
         * A backup that stops running after launch, a key rotated to a
         * development one by mistake. The widget is not a launch ceremony — it
         * is a statement about the system's current state.
         */
        $this->actingAs($this->owner);
        $this->clearEverything();
        $this->assertFalse(LaunchReadinessSummary::canView());

        BackupRun::query()->delete();
        app(LaunchReadiness::class)->forget();

        $this->assertTrue(LaunchReadinessSummary::canView());
    }

    public function test_it_shows_only_a_handful_and_says_how_many_more(): void
    {
        // Fifteen rows on a dashboard panel is a page, and somebody has to
        // scroll past it every morning to reach the orders.
        $this->actingAs($this->owner);

        $widget = new LaunchReadinessSummary;

        $this->assertCount(4, $widget->topOutstanding());
        $this->assertSame($widget->outstanding() - 4, $widget->moreCount());
    }

    public function test_failing_checks_come_before_unrecorded_paperwork(): void
    {
        /*
         * A check that fails is a fact about the system right now. An
         * unattested item may only mean nobody has recorded something that was
         * done months ago, so it is the less urgent of the two.
         */
        $this->actingAs($this->owner);

        $kinds = array_map(
            fn ($c) => $c->jenis,
            app(LaunchReadiness::class)->outstandingChecks(),
        );

        $firstAttested = array_search(LaunchCheckKind::Pernyataan, $kinds, true);
        $lastAutomatic = array_keys($kinds, LaunchCheckKind::Otomatis, true);

        if ($firstAttested !== false && $lastAutomatic !== []) {
            $this->assertLessThan($firstAttested, max($lastAutomatic));
        }
    }

    public function test_the_dashboard_renders_it_for_the_owner(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LaunchReadinessSummary::class)
            ->assertOk()
            ->assertSee('belum selesai sebelum sistem ini dipakai sungguhan');
    }

    public function test_the_widget_and_the_page_agree_on_the_count(): void
    {
        // Two figures for the same thing is how a dashboard stops being
        // trusted. Both read the same domain object.
        $this->actingAs($this->owner);

        $this->assertSame(
            app(LaunchReadiness::class)->outstanding(),
            (new LaunchReadinessSummary)->outstanding(),
        );
    }

    /** Everything a launch actually needs, so the widget has nothing to say. */
    private function clearEverything(): void
    {
        config([
            'perusahaan.legal' => ['nib' => '9120000000000', 'npwp' => '01.234.567.8-901.000'],
            'perusahaan.kontak' => [
                'alamat' => 'Jl. Raya Bekasi KM 25, Jakarta Timur',
                'telepon' => '+62 21 5555 1234',
                'whatsapp' => '+62 811 2233 4455',
                'email' => 'sales@javaindo.co.id',
            ],
            'perusahaan.mitra' => [],
            'pajak.penjual' => ['npwp' => '01.234.567.8-901.000', 'nama' => 'PT Java Indo'],
            'xendit.secret_key' => 'xnd_production_abc123',
            'xendit.callback_token' => 'tok',
        ]);

        PriceListVersion::factory()->create(['published_at' => now()]);
        Order::factory()->create(['status' => OrderStatus::Completed]);
        BackupRun::factory()->offsite()->create();

        User::query()->update(['password' => Hash::make('not-the-seeded-one')]);

        $recorder = app(AttestationRecorder::class);

        foreach (['pse', 'kbli', 'legal_ditinjau', 'nilai_komersial', 'format_faktur', 'restore_dilatih'] as $kunci) {
            $recorder->attest($kunci, $this->owner, 'bukti');
        }

        app(LaunchReadiness::class)->forget();
    }
}
