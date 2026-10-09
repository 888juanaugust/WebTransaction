<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Seeders\BranchSeeder;
use App\Client\Seeders\DocumentSeriesSeeder;
use App\Modules\BaseModule;

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
        return [];
    }

    public static function defaultSeeders(): array
    {
        return [BranchSeeder::class, DocumentSeriesSeeder::class];
    }
}
