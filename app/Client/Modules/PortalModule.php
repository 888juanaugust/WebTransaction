<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\PruneCartsCommand;
use App\Client\Models\CustomerUser;
use App\Client\Models\PortalCart;
use App\Client\Models\PortalCartLine;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\PortalUserSeeder;
use App\Modules\BaseModule;
use Illuminate\Console\Scheduling\Schedule;

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
        return ['customer_user' => CustomerUser::class, 'portal_cart' => PortalCart::class, 'portal_cart_line' => PortalCartLine::class];
    }

    public static function defaultSeeders(): array
    {
        return [PortalUserSeeder::class];
    }

    public static function commands(): array
    {
        return [PruneCartsCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command('central:prune-carts')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
    }
}
