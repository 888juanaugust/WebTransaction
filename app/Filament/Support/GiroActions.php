<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\CashBank\GiroService;
use App\Domain\Shared\Format;
use App\Models\CashBank\Giro;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * The giro register's two moves, offered on the list of every document that
 * can be settled by cheque: the bank honoured the giro, or refused it. The
 * list must eager-load the document's `giro` relation.
 */
final class GiroActions
{
    /** @return list<Action> */
    public static function forRecord(): array
    {
        return [
            Action::make('giroCleared')
                ->label(__('Giro cleared'))
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->visible(fn (Model $record) => ($record->giro?->isOutstanding() ?? false) && app(GiroService::class)->allows($record->giro))
                ->schema([
                    DatePicker::make('on')->label(__('Cleared on'))->required()->native(false)->default(today()),
                ])
                ->requiresConfirmation()
                ->modalDescription(fn (Model $record) => 'Giro '.$record->giro->number.' for '.Format::rupiah($record->giro->amount).' leaves giros receivable/payable for the bank on that day.')
                ->action(function (Model $record, array $data): void {
                    try {
                        app(GiroService::class)->clear($record->giro, $data['on']);
                        Notification::make()->title(__('Giro :number cleared', ['number' => $record->giro->number]))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot clear'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('giroBounced')
                ->label(__('Giro bounced'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->visible(fn (Model $record) => ($record->giro?->isOutstanding() ?? false) && app(GiroService::class)->allows($record->giro))
                ->schema([
                    DatePicker::make('on')->label(__('Bounced on'))->required()->native(false)->default(today()),
                    Textarea::make('reason')->label(__('Reason'))->rows(2),
                ])
                ->requiresConfirmation()
                ->modalDescription(__('What the receipt or payment did is reversed on that day: what it settled is open again.'))
                ->action(function (Model $record, array $data): void {
                    try {
                        app(GiroService::class)->bounce($record->giro, $data['on'], $data['reason'] ?? null);
                        Notification::make()->title(__('Giro :number bounced', ['number' => $record->giro->number]))->warning()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot bounce'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /** The badge colour of a giro's status on a list. */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            Giro::OUTSTANDING => 'warning',
            Giro::CLEARED => 'success',
            Giro::BOUNCED => 'danger',
            default => 'gray',
        };
    }
}
