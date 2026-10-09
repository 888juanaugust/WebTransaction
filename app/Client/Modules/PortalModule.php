<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Models\CustomerUser;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\PortalUserSeeder;
use App\Modules\BaseModule;

/**
 * The buyer portal's staff side: the buyer accounts staff invite, and the
 * Portal system user in whose name the portal writes. The panel itself is
 * registered by the client service provider. Always on.
 */
final class PortalModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-portal';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::BuyerAccounts];
    }

    public static function morphMap(): array
    {
        return ['customer_user' => CustomerUser::class];
    }

    public static function defaultSeeders(): array
    {
        return [PortalUserSeeder::class];
    }
}
