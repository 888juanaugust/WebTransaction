<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\ReshapeGroupsCommand;
use App\Client\Domain\Orders\SeatApproval;
use App\Client\Domain\Stock\Reservations;
use App\Client\Models\StockReservation;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\BranchSeeder;
use App\Client\Seeders\CentralGroupSeeder;
use App\Client\Seeders\DocumentSeriesSeeder;
use App\Client\Seeders\PreferenceSeeder;
use App\Models\Settings\AccessGroup;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
use RuntimeException;

/**
 * Central's order flow on top of the base's sales module: branches as
 * cabang with codes in document numbers, the team per customer, approval by
 * the customer's marketing seat, the stock reservations ledger and the split
 * of an order across warehouses. Always on.
 */
final class OrdersModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-orders';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::Teams, CentralScreen::OrderApprovals];
    }

    public static function morphMap(): array
    {
        return ['stock_reservation' => StockReservation::class];
    }

    public static function boot(ModuleContext $context): void
    {
        // The customer's marketing seat approves, the credit check runs, and the reservations ledger follows the decision.
        $context->approvals->register(SeatApproval::type());

        // A delivery consumes what its order lines held; goods held for other orders never leave; an unposted delivery gives the hold back.
        $context->postings->extend(fn ($posting, $builder) => $context->app->make(Reservations::class)->consume($posting, $builder));
        $context->postings->onUnpost(fn ($posting) => $context->app->make(Reservations::class)->unpost($posting));

        // A group that is one of Central's roles may be renamed, never deleted: the team rules read it by its key.
        AccessGroup::deleting(function (AccessGroup $group): void {
            if ($group->role_key !== null) {
                throw new RuntimeException(__(':name is one of Central\'s roles and cannot be deleted; rename it or empty it instead.', ['name' => $group->name]));
            }
        });
    }

    public static function commands(): array
    {
        return [ReshapeGroupsCommand::class];
    }

    public static function defaultSeeders(): array
    {
        return [BranchSeeder::class, DocumentSeriesSeeder::class, CentralGroupSeeder::class, PreferenceSeeder::class];
    }
}
