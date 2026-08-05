<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Actions\OrderTransitionActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One order, and everything a staff member can do to it.
 *
 * The actions are the same objects the list and the dashboard queues use, so
 * an order can be worked from wherever it was found rather than only from the
 * queue that happened to surface it.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return "Order {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return OrderTransitionActions::all();
    }
}
