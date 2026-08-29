<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Access\Role;
use App\Models\User;
use App\Notifications\PeringatanSistem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            Cache::forget(self::THROTTLE_KEY);

            return;
        }

        // add() is the atomic "am I first": false means an alert already
        // stands for this incident window.
        if (! Cache::add(self::THROTTLE_KEY, time(), now()->addHours(self::THROTTLE_HOURS))) {
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
}
