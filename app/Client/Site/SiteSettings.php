<?php

declare(strict_types=1);

namespace App\Client\Site;

use App\Client\Models\SiteSetting;
use App\Domain\Audit\Auditor;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Owner's overrides of the public site's copy, keyed like config/site.php
 * ("contact.phone", "tagline", "partners"). A stored key wins over the
 * config; a key stored above the one asked for is dug into, so "legal.nib"
 * answers from a stored "legal" as well. Every change is audited with the
 * value before and after. Read once per request.
 */
class SiteSettings
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function value(string $key): mixed
    {
        $stored = $this->all();
        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }
        $parts = explode('.', $key);
        for ($i = count($parts) - 1; $i > 0; $i--) {
            $parent = implode('.', array_slice($parts, 0, $i));
            if (array_key_exists($parent, $stored) && is_array($stored[$parent])) {
                $rest = implode('.', array_slice($parts, $i));

                return Arr::has($stored[$parent], $rest) ? Arr::get($stored[$parent], $rest) : null;
            }
        }

        return null;
    }

    /** Stores a value, or forgets the key when the value is the config's own, and audits the change. */
    public function set(string $key, mixed $value, ?User $actor = null): void
    {
        $default = config("site.{$key}");
        $before = $this->value($key) ?? $default;
        $same = fn ($a, $b) => json_encode($a) === json_encode($b);
        if ($same($value, $default) || $value === null || $value === '') {
            if (array_key_exists($key, $this->all())) {
                SiteSetting::query()->whereKey($key)->delete();
                Auditor::log('site_setting_changed', null, $key, ['key' => $key, 'before' => $before, 'after' => $default]);
            }
            $this->stored = null;

            return;
        }
        if ($same($before, $value) && array_key_exists($key, $this->all())) {
            return;
        }
        SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actor?->id ?? auth()->id(), 'updated_at' => now()]);
        Auditor::log('site_setting_changed', null, $key, ['key' => $key, 'before' => $before, 'after' => $value]);
        $this->stored = null;
    }

    /** @param  array<string, mixed>  $values */
    public function setMany(array $values, ?User $actor = null): void
    {
        DB::transaction(function () use ($values, $actor): void {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $actor);
            }
        });
    }

    public function lastChangedAt(): ?CarbonInterface
    {
        try {
            $max = SiteSetting::query()->max('updated_at');
        } catch (Throwable) {
            return null; // no database yet
        }

        return $max ? Carbon::parse($max) : null;
    }

    public function forget(): void
    {
        $this->stored = null;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->stored === null) {
            try {
                $this->stored = SiteSetting::query()->pluck('value', 'key')->all();
            } catch (Throwable) {
                $this->stored = []; // no database yet (the config is read while booting)
            }
        }

        return $this->stored;
    }
}
