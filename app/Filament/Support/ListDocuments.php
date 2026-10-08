<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Fulfilment\StatusDeriver;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** A document list: status tabs with counts, as the standard's "Status" filter. */
abstract class ListDocuments extends ListRecords
{
    protected string $statusColumn = 'status';

    /** @return array<string, string> status value → label */
    protected function statuses(): array
    {
        return [
            StatusDeriver::PENDING => __('status.fulfilment.pending'),
            StatusDeriver::PARTIAL => __('status.fulfilment.partial'),
            StatusDeriver::PROCESSED => __('status.fulfilment.processed'),
            StatusDeriver::CLOSED => __('status.fulfilment.closed'),
        ];
    }

    public function getTabs(): array
    {
        $model = static::getResource()::getModel();
        $tabs = ['all' => Tab::make(__('All'))];
        foreach ($this->statuses() as $value => $label) {
            $tabs[$value] = Tab::make($label)
                ->badge(fn () => $model::query()->where($this->statusColumn, $value)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where($this->statusColumn, $value));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New :record', ['record' => strtolower(static::getResource()::getModelLabel())]))];
    }
}
