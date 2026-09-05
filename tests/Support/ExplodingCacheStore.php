<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache that is switched on and refuses to answer.
 *
 * Not a null store — a null store returns misses, which is a working cache
 * with nothing in it, and every caller handles that correctly by definition.
 * What breaks things is a cache that *throws*, which is what `phpredis` does
 * when the server is not there: `RedisException: Connection refused`.
 *
 * Registered as a driver rather than mocked on the facade so the failure
 * arrives through the same path a real outage would — from inside the store,
 * on whichever method the code under test happens to call.
 */
class ExplodingCacheStore implements Store
{
    public const MESSAGE = 'Connection refused';

    public function get($key): mixed
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function many(array $keys): array
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function increment($key, $value = 1): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function decrement($key, $value = 1): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function touch($key, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function forget($key): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function flush(): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function getPrefix(): string
    {
        return '';
    }
}
