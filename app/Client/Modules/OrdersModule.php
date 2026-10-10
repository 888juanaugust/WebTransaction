<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\DeliveryWatchCommand;
use App\Client\Console\ReshapeGroupsCommand;
use App\Client\Domain\Orders\DeliveryWatch;
use App\Client\Domain\Orders\SeatApproval;
use App\Client\Domain\Stock\Reservations;
use App\Client\Models\OrderDeliveryNotice;
use App\Client\Models\StockReservation;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\BranchSeeder;
use App\Client\Seeders\CentralGroupSeeder;
use App\Client\Seeders\DocumentSeriesSeeder;
use App\Client\Seeders\PreferenceSeeder;
use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Company\CalendarFeed;
use App\Models\Sales\SalesOrder;
use App\Models\Settings\AccessGroup;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
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
        return [CentralScreen::Teams, CentralScreen::OrderApprovals, CentralScreen::DeliveryWatch];
    }

    public static function morphMap(): array
    {
        return ['stock_reservation' => StockReservation::class, 'order_delivery_notice' => OrderDeliveryNotice::class];
    }

    public static function boot(ModuleContext $context): void
    {
        // The customer's marketing seat approves, the credit check runs, and the reservations ledger follows the decision.
        $context->approvals->register(SeatApproval::type());

        // A delivery consumes what its order lines held; goods held for other orders never leave; an unposted delivery gives the hold back.
        $context->postings->extend(fn ($posting, $builder) => $context->app->make(Reservations::class)->consume($posting, $builder));
        $context->postings->onUnpost(fn ($posting) => $context->app->make(Reservations::class)->unpost($posting));

        // The calendar shows the day an accepted order crosses the watch days while its goods have not all gone out.
        CalendarFeed::extend('delivery-watch', fn () => __('Delivery over :days days', ['days' => (int) config('orders.watch_days', 30)]), 'bg-orange-50 text-orange-800',
            function (CarbonImmutable $from, CarbonImmutable $until, callable $add) use ($context): void {
                $user = auth()->user();
                if ($user !== null && ! app(HakAkses::class)->allows($user, MenuKey::SalesOrders, Hak::View)) {
                    return;
                }
                $watch = $context->app->make(DeliveryWatch::class);
                $days = $watch->days();
                $orders = BranchLimit::apply(SalesOrder::query()->with(['customer', 'lines']), $user)
                    ->where('approval_status', SalesOrder::APPROVED)->whereNotNull('approved_at')
                    ->whereIn('status', ['pending', 'partial'])
                    ->whereBetween(DB::raw('(approved_at::date)'), [$from->subDays($days)->toDateString(), $until->subDays($days)->toDateString()])
                    ->get();
                foreach ($orders as $order) {
                    $add(CarbonImmutable::parse($order->approved_at)->addDays($days)->toDateString(), 'delivery-watch', __('Not delivered: :number · :party', ['number' => $order->number, 'party' => $order->customer?->name ?? '']));
                }
            });

        // A group that is one of Central's roles may be renamed, never deleted: the team rules read it by its key.
        AccessGroup::deleting(function (AccessGroup $group): void {
            if ($group->role_key !== null) {
                throw new RuntimeException(__(':name is one of Central\'s roles and cannot be deleted; rename it or empty it instead.', ['name' => $group->name]));
            }
        });
    }

    public static function commands(): array
    {
        return [ReshapeGroupsCommand::class, DeliveryWatchCommand::class];
    }

    public static function defaultSeeders(): array
    {
        return [BranchSeeder::class, DocumentSeriesSeeder::class, CentralGroupSeeder::class, PreferenceSeeder::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command('central:delivery-watch')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
    }
}
