<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Access\Role;
use App\Models\User;
use App\Notifications\PeringatanSistem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails the Owner when the box is broken — once per incident, not hourly.
 *
 * The throttle is the design: an alert that repeats every hour is muted by
 * Thursday, and a muted alert is no alert. One mail when things go gawat,
 * silence while they stay gawat, and the throttle clears the moment the
 * system is healthy again — so the *next* incident mails immediately
 * rather than inheriting the last one's cooldown.
 */
class OpsAlerter
{
    public const THROTTLE_KEY = 'ops:alert-throttled';

    /** How long one incident's alert stands for before a reminder. */
    private const THROTTLE_HOURS = 6;

    public function __construct(private readonly OpsHealth $health) {}

    public function sweep(): void
    {
        if ($this->health->worst() !== OpsStatus::Gawat) {
            try {
                Cache::forget(self::THROTTLE_KEY);
            } catch (Throwable) {
                // Nothing is wrong; failing to clear a throttle is not worth
                // failing a scheduled sweep over.
            }

            return;
        }

        if (! $this->claimTheIncident()) {
            return;
        }

        $failing = $this->health->failing();

        $owners = User::query()
            ->where('role', Role::Owner->value)
            ->where('is_active', true)
            ->get();

        foreach ($owners as $owner) {
            $owner->notify(new PeringatanSistem($failing));
        }

        Log::warning('Peringatan kesehatan sistem dikirim', [
            'temuan' => array_map(fn (OpsCheck $c) => "{$c->kunci}: {$c->temuan}", $failing),
        ]);
    }

    /**
     * Am I the first to see this incident?
     *
     * `add()` is the atomic answer: false means an alert already stands for
     * this window. But the throttle lives in the cache, and **the cache is
     * one of the things that can be gawat** — so when Redis was the thing
     * that had failed, `OpsHealth` correctly reported it and then this line
     * threw on the way to telling anybody. The alerter died of the outage it
     * had just detected, which is the one failure a monitor may not have.
     *
     * Unreachable cache therefore means *send*. That can repeat the mail
     * every sweep for as long as the cache is down, and repeating is the
     * right side to err on: an alert nobody wanted is a nuisance, and an
     * outage nobody hears about is the reason this class exists. The
     * repetition also stops by itself, because it stops when the cache
     * — the thing being complained about — comes back.
     */
    private function claimTheIncident(): bool
    {
        try {
            return Cache::add(self::THROTTLE_KEY, time(), now()->addHours(self::THROTTLE_HOURS));
        } catch (Throwable) {
            return true;
        }
    }
}
