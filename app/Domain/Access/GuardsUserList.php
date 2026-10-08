<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * For a record that names who may use it (a branch, a warehouse, an account, a print layout, a number series):
 * opening it to everyone is an administrator's change, never an operator's (who could otherwise open a branch to
 * themselves). The screen disables the list for operators; this is the check behind it.
 */
trait GuardsUserList
{
    public static function bootGuardsUserList(): void
    {
        static::saving(function (Model $record): void {
            $actor = auth()->user();
            if (! $actor instanceof User || $actor->getOriginal('access_type') === 'administrator') {
                return; // the console, or an administrator
            }
            if ($record->exists && $record->isDirty('used_all_user')) {
                throw ValidationException::withMessages(['data.used_all_user' => __('Only an administrator changes who may use :name.', ['name' => $record->getAttribute('name') ?? $record->getKey()])]);
            }
        });
    }
}
