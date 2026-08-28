<?php

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Models\Region;
use RuntimeException;

/**
 * Which region the current request is working in.
 *
 * Every scoped model reads this through a global scope, so this object decides
 * what an entire request can see. It has three states and the difference
 * between two of them is only in what they say when something goes wrong:
 *
 * - **pinned** — filter to one region, and stamp new rows with it. Ordinary
 *   staff, all day.
 * - **open** — a person is deliberately looking across every region. Queries
 *   are unfiltered; creating anything is refused, because "which region does
 *   this order belong to" has no answer in this state.
 * - **unbound** — console commands, queue workers, the webhook. Also
 *   unfiltered, and also refuses to stamp, but the refusal reads as the
 *   programming error it is rather than as advice to a user.
 *
 * Unfiltered is the safe default for the two states with nobody in front of
 * them. The alternative — filtering on a null region — would quietly return no
 * rows, and a nightly job that reconciles nothing while reporting success is
 * worse than one that crashes.
 *
 * Bound per request. The container registers it scoped, so a queue worker
 * handling two jobs does not carry the first job's region into the second.
 */
class RegionContext
{
    private ?int $regionId = null;

    private bool $terbuka = false;

    /** Work inside one region: filter to it, and stamp new rows with it. */
    public function pinTo(Region|int $region): void
    {
        $this->regionId = $region instanceof Region ? (int) $region->getKey() : $region;
        $this->terbuka = false;
    }

    /** Look across every region. Reads are unfiltered; writes are refused. */
    public function openToAll(): void
    {
        $this->regionId = null;
        $this->terbuka = true;
    }

    /** Back to knowing nothing — what a fresh console command or job starts as. */
    public function release(): void
    {
        $this->regionId = null;
        $this->terbuka = false;
    }

    /**
     * The region to filter by, or null for no filtering at all.
     *
     * Null is returned for both "open" and "unbound", because for the purpose
     * of a WHERE clause they are the same thing.
     */
    public function regionId(): ?int
    {
        return $this->regionId;
    }

    public function isPinned(): bool
    {
        return $this->regionId !== null;
    }

    public function isOpenToAll(): bool
    {
        return $this->terbuka;
    }

    public function region(): ?Region
    {
        return $this->regionId === null ? null : Region::find($this->regionId);
    }

    /**
     * The region a new row belongs to.
     *
     * Throws rather than guessing. A default here would be the single most
     * expensive line in the system: it would file a Surabaya invoice in the
     * Jakarta books, and nothing would fail until somebody read a neraca
     * months later and found figures that did not match the warehouse.
     */
    public function requireRegionId(): int
    {
        if ($this->regionId !== null) {
            return $this->regionId;
        }

        throw new RuntimeException($this->terbuka
            ? 'Sedang melihat semua wilayah. Pilih satu wilayah dulu sebelum membuat data baru.'
            : 'No region is bound, so there is nothing to file this row under. '
                .'A job or console command creating scoped rows must set region_id itself, '
                .'or run inside RegionContext::pinTo().');
    }

    /**
     * Run something inside a region, then put the context back as it was.
     *
     * For the cases where one region's work has to happen while another is
     * bound — a job iterating every region, a test checking that two sets of
     * books stay apart.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function within(Region|int $region, callable $work): mixed
    {
        $regionSebelumnya = $this->regionId;
        $terbukaSebelumnya = $this->terbuka;

        $this->pinTo($region);

        try {
            return $work();
        } finally {
            $this->regionId = $regionSebelumnya;
            $this->terbuka = $terbukaSebelumnya;
        }
    }

    /**
     * Run something across every region, then put the context back.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function acrossAll(callable $work): mixed
    {
        $regionSebelumnya = $this->regionId;
        $terbukaSebelumnya = $this->terbuka;

        $this->openToAll();

        try {
            return $work();
        } finally {
            $this->regionId = $regionSebelumnya;
            $this->terbuka = $terbukaSebelumnya;
        }
    }
}
