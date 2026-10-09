<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Pages;

use App\Client\Portal\Filament\Widgets\LastOrders;
use App\Client\Portal\Filament\Widgets\OpenInvoices;
use App\Client\Portal\Filament\Widgets\ReorderLastOrder;
use Filament\Pages\Dashboard;

/** The buyer's home: reorder the last order, the open invoices, the last orders; the credit strip sits above every page. */
class Home extends Dashboard
{
    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return __('Home');
    }

    public function getTitle(): string
    {
        return __('Home');
    }

    public function getWidgets(): array
    {
        return [ReorderLastOrder::class, OpenInvoices::class, LastOrders::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
