<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\DebtNoticesCommand;
use App\Client\Models\CollectionContact;
use App\Client\Models\DebtNotice;
use App\Client\Screens\CentralScreen;
use App\Modules\BaseModule;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Debt collection: the aging notice to the customer and the team, and the
 * collection desk where the team records contacts and promises. Always on.
 */
final class CollectionsModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-collections';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::Collections];
    }

    public static function morphMap(): array
    {
        return ['debt_notice' => DebtNotice::class, 'collection_contact' => CollectionContact::class];
    }

    public static function commands(): array
    {
        return [DebtNoticesCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command('central:debt-notices')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
    }
}
