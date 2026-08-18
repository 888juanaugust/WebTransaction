<?php

declare(strict_types=1);

namespace App\Filament\Resources\Giros\Pages;

use App\Filament\Actions\GiroTransitionActions;
use App\Filament\Resources\Giros\GiroResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One giro, with every transition that still applies.
 *
 * Each action hides itself unless it can run, so a settled giro shows none at
 * all — the record and what it did to the books, and nothing to click.
 */
class ViewGiro extends ViewRecord
{
    protected static string $resource = GiroResource::class;

    public function getTitle(): string
    {
        return "Giro {$this->record->nomor_warkat}";
    }

    protected function getHeaderActions(): array
    {
        return GiroTransitionActions::all();
    }
}
