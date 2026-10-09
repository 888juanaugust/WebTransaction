<?php

declare(strict_types=1);

namespace App\Client\Site\Http;

use App\Client\Site\Sitemap;
use Illuminate\Http\Response;

/** What crawlers may index: the public pages, never the panels. */
class RobotsController
{
    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Allow: /', 'Disallow: /admin', 'Disallow: /portal', 'Disallow: /livewire', '', 'Sitemap: '.route('site.sitemap'), ''];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(Sitemap $sitemap): Response
    {
        return response(view('client.site.sitemap', ['urls' => $sitemap->urls()])->render(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
