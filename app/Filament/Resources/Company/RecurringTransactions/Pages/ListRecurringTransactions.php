<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\RecurringTransactions\Pages;

use App\Domain\Company\RecurringRunner;
use App\Filament\Resources\Company\RecurringTransactions\RecurringTransactionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRecurringTransactions extends ListRecords
{
    protected static string $resource = RecurringTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runDue')
                ->label(__('Run everything due'))
                ->icon('heroicon-m-play')
                ->color('gray')
                ->visible(fn (): bool => RecurringTransactionResource::canCreate())
                ->requiresConfirmation()
                ->modalDescription(__('Makes and posts a document for every active schedule whose next run is today or earlier.'))
                ->action(function (): void {
                    ['made' => $made, 'failed' => $failed] = app(RecurringRunner::class)->runDue();
                    if ($failed !== []) {
                        Notification::make()->title(__(':count schedule(s) not run', ['count' => count($failed)]))->body(implode("\n", $failed))->danger()->persistent()->send();
                    }
                    if ($made === [] && $failed === []) {
                        Notification::make()->title(__('Nothing was due'))->info()->send();

                        return;
                    }
                    if ($made === []) {
                        return;
                    }
                    Notification::make()->title(__(':count document(s) made', ['count' => count($made)]))->body(implode("\n", $made))->success()->send();
                }),
            CreateAction::make()->label(__('New recurring transaction')),
        ];
    }
}
