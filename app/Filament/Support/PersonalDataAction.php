<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Privacy\PersonalData;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Export personal data" on a customer, vendor or employee: everything held about them, as a JSON file (docs/PRIVACY.md). */
final class PersonalDataAction
{
    public static function make(): Action
    {
        return Action::make('exportPersonalData')
            ->label(__('Export personal data'))
            ->icon('heroicon-m-identification')
            ->color('gray')
            ->visible(fn (): bool => HakAkses::canSpecial(HakKhusus::ExportData))
            ->requiresConfirmation()
            ->modalDescription(__('Everything held about them, for a request under the personal data protection law. The export is logged.'))
            ->action(function (Model $record): StreamedResponse {
                $json = PersonalData::json($record);

                return response()->streamDownload(fn () => print ($json), 'personal-data-'.Str::slug((string) ($record->getAttribute('number') ?? $record->getKey())).'.json', ['Content-Type' => 'application/json']);
            });
    }
}
