<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Visits\StoreVisits;
use App\Models\Company;
use App\Models\StoreVisit;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kunjungan toko: the seat rule, the un-editable timestamp, the retention.
 */
class StoreVisitTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private Company $toko;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StoreVisits::DISK);

        $owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->toko = Company::factory()->create();
        app(TeamAssigner::class)->assignSales($this->toko, $this->sales, $owner);
    }

    private function visits(): StoreVisits
    {
        return app(StoreVisits::class);
    }

    public function test_a_sales_records_a_visit_with_photo_location_and_the_moment(): void
    {
        Storage::disk(StoreVisits::DISK)->put('kunjungan/foto-1.jpg', 'isi-foto');

        $visit = $this->visits()->record(
            $this->sales, $this->toko,
            latitude: -6.2001234, longitude: 106.8167890,
            fotoPath: 'kunjungan/foto-1.jpg',
            catatan: 'Stok rak depan tipis.',
        );

        $this->assertSame($this->sales->id, $visit->sales_user_id);
        $this->assertSame('-6.2001234', (string) $visit->latitude);
        // The timestamp is the recording moment, not an input.
        $this->assertTrue($visit->visited_at->isToday());
    }

    public function test_only_the_customers_own_sales_records_their_visit(): void
    {
        $lain = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/bukan tanggung jawab Anda/');

        $this->visits()->record($lain, $this->toko, null, null, null);
    }

    public function test_the_purge_deletes_old_photos_but_keeps_the_visit(): void
    {
        Storage::disk(StoreVisits::DISK)->put('kunjungan/lama.jpg', 'x');
        Storage::disk(StoreVisits::DISK)->put('kunjungan/baru.jpg', 'y');

        $lama = StoreVisit::factory()->create([
            'sales_user_id' => $this->sales->id,
            'company_id' => $this->toko->id,
            'foto_path' => 'kunjungan/lama.jpg',
            'visited_at' => now()->subDays(StoreVisits::RETENTION_DAYS + 1),
        ]);
        $baru = StoreVisit::factory()->create([
            'sales_user_id' => $this->sales->id,
            'company_id' => $this->toko->id,
            'foto_path' => 'kunjungan/baru.jpg',
            'visited_at' => now()->subDays(5),
        ]);

        $purged = $this->visits()->purgeExpiredPhotos();

        $this->assertSame(1, $purged);
        Storage::disk(StoreVisits::DISK)->assertMissing('kunjungan/lama.jpg');
        Storage::disk(StoreVisits::DISK)->assertExists('kunjungan/baru.jpg');

        // The row outlives its photo, stamped with when the file went.
        $this->assertNotNull($lama->refresh()->foto_dihapus_pada);
        $this->assertNotNull($lama->visited_at);
        $this->assertNull($baru->refresh()->foto_dihapus_pada);

        // Running again finds nothing: the stamp is the claim.
        $this->assertSame(0, $this->visits()->purgeExpiredPhotos());
    }

    public function test_finance_archives_a_month_as_a_zip_with_the_csv(): void
    {
        Storage::disk(StoreVisits::DISK)->put('kunjungan/arsip.jpg', 'foto');
        StoreVisit::factory()->create([
            'sales_user_id' => $this->sales->id,
            'company_id' => $this->toko->id,
            'foto_path' => 'kunjungan/arsip.jpg',
            'visited_at' => now(),
        ]);

        $finance = User::factory()->finance()->create();

        $zipPath = $this->visits()->archiveMonth(now(), $finance);

        $this->assertFileExists($zipPath);

        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $this->assertNotFalse($zip->locateName('kunjungan.csv'));
        $this->assertSame(2, $zip->numFiles); // csv + one photo
        $zip->close();

        unlink($zipPath);
    }

    public function test_a_sales_cannot_pull_the_archive(): void
    {
        StoreVisit::factory()->create([
            'sales_user_id' => $this->sales->id,
            'company_id' => $this->toko->id,
            'visited_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/pemilik dan finance/');

        $this->visits()->archiveMonth(now(), $this->sales);
    }
}
