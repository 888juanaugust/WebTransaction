<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesOrders\Pages;

use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\ListDocuments;
use App\Models\Sales\SalesOrder;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSalesOrders extends ListDocuments
{
    protected static string $resource = SalesOrderResource::class;

    public function getTabs(): array
    {
        return ['awaiting' => Tab::make(__('Awaiting approval'))
            ->badge(fn () => SalesOrder::query()->where('approval_status', SalesOrder::AWAITING)->count())
            ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', SalesOrder::AWAITING)),
        ] + parent::getTabs();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
