<?php

namespace Tests\Support;

use App\Domain\Access\MenuKey;
use App\Modules\BaseModule;

/** A client module as a test sees it: a key and nothing else. */
final class FakeModule extends BaseModule
{
    public static function key(): string
    {
        return 'fake-client-module';
    }

    /** @return list<MenuKey> */
    public static function menuKeys(): array
    {
        return [];
    }
}
