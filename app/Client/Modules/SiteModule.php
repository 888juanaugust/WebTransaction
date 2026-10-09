<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Models\SiteImage;
use App\Client\Models\SiteSetting;
use App\Client\Screens\CentralScreen;
use App\Modules\BaseModule;

/**
 * The public site's staff side: the Website screen where the Owner
 * overrides the company's copy, and the images (promos and photos) the
 * home page shows. The site itself is routed by the client service
 * provider. Always on.
 */
final class SiteModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-site';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::Website, CentralScreen::SiteImages];
    }

    public static function morphMap(): array
    {
        return ['site_setting' => SiteSetting::class, 'site_image' => SiteImage::class];
    }
}
