<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\PrintLayouts\Pages;

use App\Filament\Resources\Settings\PrintLayouts\PrintLayoutResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrintLayout extends EditRecord
{
    protected static string $resource = PrintLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
