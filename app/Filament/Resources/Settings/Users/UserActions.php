<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\Users;

use App\Domain\Access\HakAkses;
use App\Domain\Access\UserDeactivation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** Deactivate and Reactivate, on the Users list and the user's page: a user is never deleted. */
final class UserActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label(__('Deactivate'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (User $record): bool => $record->is_active && ! $record->is(auth()->user()))
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Deactivate :name', ['name' => $record->name]))
            ->modalDescription(__('They can no longer sign in, and an open session ends at their next click. Their name stays on everything they entered or approved.'))
            ->modalSubmitActionLabel(__('Deactivate'))
            ->action(function (User $record, $livewire): void {
                try {
                    UserDeactivation::assertAllowed($record, auth()->user());
                    $record->update(['is_active' => false]);
                } catch (RuntimeException $e) {
                    $record->refresh();
                    Notification::make()->title(__('Cannot deactivate :name', ['name' => $record->name]))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                self::changed($record, $livewire);
                Notification::make()->title(__(':name is deactivated.', ['name' => $record->name]))->success()->send();
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label(__('Reactivate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (User $record): bool => ! $record->is_active)
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Reactivate :name', ['name' => $record->name]))
            ->modalDescription(__('They can sign in again with the rights of their access groups.'))
            ->modalSubmitActionLabel(__('Reactivate'))
            ->action(function (User $record, $livewire): void {
                $record->update(['is_active' => true]);
                self::changed($record, $livewire);
                Notification::make()->title(__(':name is active again.', ['name' => $record->name]))->success()->send();
            });
    }

    /** Rights are cached per request; the user's page shows the new switch. */
    private static function changed(User $record, mixed $livewire): void
    {
        app(HakAkses::class)->forget();
        if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
            $livewire->refreshFormData(['is_active']);
        }
    }
}
