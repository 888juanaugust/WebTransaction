<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Notify\Notify;
use App\Client\Domain\Stock\OpnameScheduler;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Filament\Pages\CountSheets;
use App\Client\Mail\CountSheetsMessage;
use App\Models\Inventory\StockOpnameResult;
use Illuminate\Console\Command;

/** Every morning: the sheets not counted by their next day go to the gudang and Purchasing; the counted ones waiting for approval go to Purchasing. */
class CountRemindersCommand extends Command
{
    protected $signature = 'central:count-reminders';

    protected $description = 'Remind the gudang of count sheets to count and Purchasing of counts to approve';

    public function handle(OpnameScheduler $scheduler, WarehouseBinder $binder, Notify $notify): int
    {
        $open = $scheduler->open()->whereDate('trans_date', '<', today()->toDateString())->orderBy('trans_date')->get();
        $toCount = $open->filter(fn (StockOpnameResult $r) => $r->counted_at === null);
        $toApprove = $open->filter(fn (StockOpnameResult $r) => $r->counted_at !== null);
        $purchasing = $notify->role(CentralGroups::PURCHASING);

        if ($toCount->isNotEmpty()) {
            $holders = $toCount->map(fn (StockOpnameResult $r) => $r->order?->warehouse ? $binder->holder($r->order->warehouse) : null)->filter();
            $notify->send($holders->concat($purchasing), __(':n count sheet(s) waiting to be counted', ['n' => $toCount->count()]),
                $toCount->take(5)->map(fn ($r) => $r->number.' · '.($r->order?->warehouse?->name ?? ''))->join(' · '), CountSheets::getUrl(), new CountSheetsMessage($toCount->values()->all(), 'count'));
        }
        if ($toApprove->isNotEmpty()) {
            $notify->send($purchasing, __(':n count(s) waiting for approval', ['n' => $toApprove->count()]),
                $toApprove->take(5)->map(fn ($r) => $r->number.' · '.($r->order?->warehouse?->name ?? ''))->join(' · '), CountSheets::getUrl(), new CountSheetsMessage($toApprove->values()->all(), 'approve'));
        }
        $this->info(__(':a to count, :b to approve.', ['a' => $toCount->count(), 'b' => $toApprove->count()]));

        return self::SUCCESS;
    }
}
