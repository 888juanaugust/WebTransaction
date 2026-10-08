<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

use App\Domain\Audit\Auditor;
use App\Domain\Shared\Format;
use App\Models\Preference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The preferences store: typed reads with defaults, cached as one map; writes
 * are audited with the old and the new value. One instance per request.
 */
final class Preferensi
{
    private const CACHE_KEY = 'preferences.all';

    /** @var array<string, mixed>|null raw values by key, as stored */
    private ?array $raw = null;

    public function get(PreferensiKey $key): mixed
    {
        $raw = $this->raw()[$key->value] ?? null;

        return $raw === null ? $key->default() : $key->type()->cast($raw);
    }

    public function isOn(PreferensiKey $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @return array<string, mixed> every key with its effective value, for the form */
    public function all(): array
    {
        $values = [];
        foreach (PreferensiKey::cases() as $key) {
            $values[$key->value] = $this->get($key);
        }

        return $values;
    }

    public function set(PreferensiKey $key, mixed $value, ?User $actor = null): void
    {
        $this->setMany([$key->value => $value], $actor);
    }

    /** @param  array<string, mixed>  $values  keyed by PreferensiKey value */
    public function setMany(array $values, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        DB::transaction(function () use ($values, $actor): void {
            foreach ($values as $name => $value) {
                $key = PreferensiKey::from($name);
                $value = $this->normalise($key, $value);
                $old = $this->get($key);

                if ($old === $value) {
                    continue;
                }

                Preference::query()->updateOrCreate(
                    ['key' => $key->value],
                    ['value' => $value, 'updated_by' => $actor?->id, 'updated_at' => now()],
                );

                Auditor::log('preference_changed', null, $key->label(), [
                    'key' => $key->value,
                    'old' => $old,
                    'new' => $value,
                ]);
            }
        });

        $this->forget();
    }

    public function forget(): void
    {
        $this->raw = null;
        Cache::forget(self::CACHE_KEY);
        Format::forget(); // number and date formats are read from here
    }

    private function normalise(PreferensiKey $key, mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            $value = null;
        }

        return $value === null ? $key->default() : $key->type()->cast($value);
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        return $this->raw ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Preference::query()->pluck('value', 'key')->all(),
        );
    }
}
