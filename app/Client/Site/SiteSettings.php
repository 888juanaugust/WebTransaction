<?php

declare(strict_types=1);

namespace App\Client\Site;

/**
 * The Owner's overrides of the site's copy. Nothing stored yet: every key
 * falls through to config/site.php. The Website screen fills this in.
 */
class SiteSettings
{
    public function value(string $key): mixed
    {
        return null;
    }
}
