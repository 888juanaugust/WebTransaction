<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Orders\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Order baru'),
        ];
    }

    /** Tabs mirror the state machine, so "where is it stuck?" is one click. */
    public function getTabs(): array
    {
        $tabs = ['semua' => Tab::make('Semua')];

        foreach (OrderStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value))
                ->badge(fn () => static::getResource()::getModel()::where('status', $status->value)->count());
        }

        return $tabs;
    }
}
