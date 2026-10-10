<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Access\CentralGroups;
use App\Client\Console\CountRemindersCommand;
use App\Client\Console\OpnameSheetsCommand;
use App\Client\Domain\Stock\OpnameScheduler;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Domain\Warehouse\WarehouseScope;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\PrintLayoutSeeder;
use App\Client\Seeders\ScrapWarehouseSeeder;
use App\Client\Seeders\SystemUserSeeder;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Company\CalendarFeed;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\StockOpnameResult;
use App\Models\User;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Gudang: one active account per warehouse, bound by an administrator, and
 * the warehouse's fulfilment queue — what it holds for approved orders, to
 * pick and deliver. Always on.
 */
final class WarehouseModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-warehouse';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::Fulfilment, CentralScreen::WarehouseAccounts, CentralScreen::StockAge, CentralScreen::ProductAnalytics, CentralScreen::CountSheets, CentralScreen::DamagedGoods];
    }

    public static function boot(ModuleContext $context): void
    {
        // A scheduled count asks for named SKUs; the base's order filters by category, vendor and brand only.
        StockOpnameOrder::resolveRelationUsing('items', fn (StockOpnameOrder $order) => $order->belongsToMany(Item::class, 'stock_opname_order_items', 'stock_opname_order_id', 'item_id', 'id', 'id', 'items'));
        StockOpnameResult::resolveRelationUsing('countedBy', fn (StockOpnameResult $result) => $result->belongsTo(User::class, 'counted_by', 'id', 'countedBy'));

        // Count sheets due show in the calendar for whoever may open the Count Sheets screen.
        CalendarFeed::extend('count-sheet', fn () => __('Count sheet due'), 'bg-teal-50 text-teal-800', function (CarbonImmutable $from, CarbonImmutable $until, callable $add): void {
            $user = auth()->user();
            if ($user !== null && ! app(HakAkses::class)->allows($user, CentralScreen::CountSheets, Hak::View)) {
                return;
            }
            $sheets = app(OpnameScheduler::class)->open()->whereBetween('trans_date', [$from->toDateString(), $until->toDateString()])->get();
            foreach ($sheets as $sheet) {
                $add($sheet->trans_date->toDateString(), 'count-sheet', __('Count: :number · :warehouse', ['number' => $sheet->number, 'warehouse' => $sheet->order?->warehouse?->name ?? '']));
            }
        });

        // Reactivating a bound account while another active account holds its warehouse is refused: one warehouse, one account.
        User::updating(function (User $user) use ($context): void {
            if (! ($user->isDirty('is_active') && $user->is_active && ! (bool) $user->getOriginal('is_active'))) {
                return;
            }
            if (! CentralGroups::isMember($user, CentralGroups::WAREHOUSE)) {
                return;
            }
            $warehouse = WarehouseScope::of($user->fresh() ?? $user);
            if ($warehouse !== null) {
                $context->app->make(WarehouseBinder::class)->assertFree($warehouse, $user);
            }
        });
    }

    public static function defaultSeeders(): array
    {
        return [PrintLayoutSeeder::class, SystemUserSeeder::class, ScrapWarehouseSeeder::class];
    }

    public static function commands(): array
    {
        return [OpnameSheetsCommand::class, CountRemindersCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $at = (string) config('stock.daily_sheet_at', '17:30');
        $schedule->command('central:opname-sheets')->dailyAt($at)->days([1, 2, 3, 4, 5, 6])->withoutOverlapping()->onOneServer();
        $schedule->command('central:count-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
    }
}
