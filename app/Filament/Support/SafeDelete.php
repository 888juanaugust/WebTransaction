<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\RecordInUse;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/** What every Delete button does: delete, or say where the record is still used instead of failing with a database error. */
final class SafeDelete
{
    public static function run(Model $record): bool
    {
        try {
            return (bool) RecordInUse::guard($record, fn () => $record->delete());
        } catch (RecordInUse $e) {
            Notification::make()->title(__('Cannot delete'))->body($e->getMessage())->danger()->persistent()->send();

            return false;
        }
    }
}
