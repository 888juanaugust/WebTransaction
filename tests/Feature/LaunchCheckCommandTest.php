<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Launch\AttestationRecorder;
use App\Domain\Launch\LaunchReadiness;
use App\Domain\Orders\OrderStatus;
use App\Models\BackupRun;
use App\Models\Order;
use App\Models\PriceListVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The checklist as an exit code, so a deploy script can refuse to continue.
 *
 * Same domain object as the screen — the command cannot disagree with what
 * the Owner sees, only print it where the deployer already is.
 */
class LaunchCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_fails_and_names_what_blocks(): void
    {
        $this->artisan('launch:check')
            ->expectsOutputToContain('Rekening perusahaan sudah diisi')
            ->expectsOutputToContain('Terdaftar PSE Lingkup Privat')
            ->assertExitCode(1);
    }

    public function test_a_ready_install_exits_clean(): void
    {
        $owner = User::factory()->owner()->create();

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
            'perusahaan.rekening' => [
                'bank' => 'BCA', 'nomor' => '512-034-9911', 'atas_nama' => 'PT Java Indo',
            ],
        ]);

        PriceListVersion::factory()->create(['published_at' => now()]);
        Order::factory()->create(['status' => OrderStatus::Completed]);
        BackupRun::factory()->offsite()->create();
        User::query()->update(['password' => Hash::make('not-the-seeded-one')]);

        $recorder = app(AttestationRecorder::class);

        foreach (['pse', 'kbli', 'legal_ditinjau', 'nilai_komersial', 'format_faktur', 'restore_dilatih'] as $kunci) {
            $recorder->attest($kunci, $owner, 'bukti');
        }

        app(LaunchReadiness::class)->forget();

        $this->artisan('launch:check')->assertExitCode(0);
    }
}
