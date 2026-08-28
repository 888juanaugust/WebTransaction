<?php

namespace Tests;

use App\Domain\Regions\RegionContext;
use App\Models\Region;
use App\Models\User;
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

    private ?User $penyetuju = null;

    /**
     * Somebody who may approve any order: an Owner.
     *
     * Approval became a seat with the credit-sales reorganisation — the
     * customer's assigned marketing, or the Owner as the escape hatch — and
     * three hundred existing tests confirm orders as incidental setup on the
     * way to testing something else. They use this rather than each seating a
     * marketing on each customer, because for them approval is scaffolding;
     * the tests where the seat itself is the subject build their own
     * marketing and assign them properly.
     */
    protected function approver(): User
    {
        return $this->penyetuju ??= User::factory()->owner()->create();
    }
}
