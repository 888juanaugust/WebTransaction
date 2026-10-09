<?php

declare(strict_types=1);

namespace App\Client\Site;

use App\Client\Models\SiteImage;
use Illuminate\Support\Carbon;

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

    /** When the site's content last changed: the Owner's latest override or image. */
    public function lastModified(): ?string
    {
        $settings = app(SiteSettings::class)->lastChangedAt();
        $images = SiteImage::query()->max('updated_at');
        $latest = collect([$settings, $images ? Carbon::parse($images) : null])->filter()->max();

        return $latest?->toDateString();
    }
}
