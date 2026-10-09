<?php

declare(strict_types=1);

namespace App\Client\Site;

/** The public pages, for the sitemap: the eight routes, nothing behind a login. */
final class Sitemap
{
    /** @var list<string> */
    public const ROUTES = ['site.home', 'site.about', 'site.partners', 'site.roadmap', 'site.contact', 'site.privacy', 'site.terms', 'site.sign_in'];

    /** @return list<array{loc: string, lastmod: ?string}> */
    public function urls(): array
    {
        $lastmod = $this->lastModified();

        return array_map(fn (string $name) => ['loc' => route($name), 'lastmod' => $lastmod], self::ROUTES);
    }

    /** When the site's content last changed; nothing editable yet, so nothing to say. */
    public function lastModified(): ?string
    {
        return null;
    }
}
