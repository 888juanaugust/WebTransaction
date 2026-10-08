<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/** The one page of a small master: its list, with create and edit in a slide-over, as the standard's "Data Baru". */
abstract class ManageMaster extends ManageRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New :record', ['record' => strtolower(static::getResource()::getModelLabel())]))->slideOver(),
        ];
    }
}
