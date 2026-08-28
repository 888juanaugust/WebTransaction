<?php

namespace Tests;

use App\Domain\Regions\RegionContext;
use App\Models\Region;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests run inside a region, the same as the application does.
     *
     * Every scoped model stamps `region_id` from RegionContext on creation and
     * throws when it cannot, so an unbound suite would fail on the first
     * factory call in three hundred tests. Binding here rather than making the
     * factories pass a region keeps the factories honest about what they build
     * and matches how the code runs in production: a request is always inside
     * one region.
     *
     * Tests about regions themselves override this by pinning somewhere else,
     * usually through `RegionContext::within()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pinToDefaultRegion();
    }

    /**
     * The region the migration creates, or a fresh one if the test replaced it.
     *
     * Resolved lazily and by query rather than by a hard-coded 1, because
     * RefreshDatabase and DatabaseTruncation leave the sequence in different
     * places and a test that creates its own regions first would otherwise be
     * pinned to somebody else's.
     */
    protected function pinToDefaultRegion(): Region
    {
        $region = Region::query()->orderBy('id')->first()
            ?? Region::query()->create(['kode' => 'PST', 'nama' => 'Pusat', 'aktif' => true]);

        app(RegionContext::class)->pinTo($region);

        return $region;
    }

    /** The region this test is currently working in. */
    protected function currentRegion(): Region
    {
        return app(RegionContext::class)->region()
            ?? throw new \RuntimeException('No region is bound in this test.');
    }
}
