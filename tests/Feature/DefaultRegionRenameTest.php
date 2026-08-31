<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The placeholder region becomes Surabaya, and does not take history with it.
 *
 * A region's kode is printed into every document number it issues, so the
 * rename is allowed to touch it only while nothing has been issued. These
 * tests re-run the migration by hand against the three situations it can meet
 * — untouched and unused, untouched but already issuing numbers, and renamed
 * by a person — because the difference between them is the difference between
 * a tidy-up and a rewrite of the register.
 */
class DefaultRegionRenameTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_08_31_000100_rename_default_region_to_sby.php';

    public function test_a_fresh_install_ends_up_as_sby_surabaya(): void
    {
        // What the real cutover meets: an empty database, no numbers issued.
        $this->assertSame('SBY', $this->region()->kode);
        $this->assertSame('Surabaya', $this->region()->nama);
    }

    public function test_a_kode_that_is_already_printed_on_documents_is_left_alone(): void
    {
        $this->resetToPlaceholder();

        DB::table('document_counters')->insert([
            'region_id' => $this->region()->id,
            'scope' => 'order',
            'period' => '202608',
            'next_value' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rerunMigration();

        // The name is a label and may be corrected; the kode is in
        // SO-PST-202608-0004 and stays where the paperwork says it is.
        $this->assertSame('PST', $this->region()->kode);
        $this->assertSame('Surabaya', $this->region()->nama);
    }

    public function test_a_region_somebody_already_named_is_not_touched(): void
    {
        DB::table('regions')->where('id', $this->region()->id)
            ->update(['kode' => 'PST', 'nama' => 'Jawa Timur']);

        $this->rerunMigration();

        $this->assertSame('Jawa Timur', $this->region()->fresh()->nama);
    }

    public function test_it_will_not_collide_with_an_sby_that_already_exists(): void
    {
        $this->resetToPlaceholder();

        Region::factory()->create(['kode' => 'SBY', 'nama' => 'Surabaya Kota']);

        $this->rerunMigration();

        $this->assertSame('PST', $this->region()->kode, 'Two regions may not share a kode.');
        $this->assertSame(1, Region::query()->where('kode', 'SBY')->count());
    }

    // --------------------------------------------------------------- helpers

    /** The region the first migration created — always the lowest id. */
    private function region(): Region
    {
        return Region::query()->orderBy('id')->firstOrFail();
    }

    private function resetToPlaceholder(): void
    {
        DB::table('regions')->where('id', $this->region()->id)
            ->update(['kode' => 'PST', 'nama' => 'Pusat']);
    }

    private function rerunMigration(): void
    {
        (require self::MIGRATION)->up();
    }
}
