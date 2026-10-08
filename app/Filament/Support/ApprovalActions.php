<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Approval\ApprovalEngine;
use App\Models\Approval\ApprovalRequest;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Approve and Reject on any list of a document type the approval engine
 * knows, and the approval badge. The buttons show only to someone who may
 * decide now; the engine refuses everything else with its reason.
 */
final class ApprovalActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [self::approve(), self::reject()];
    }

    /** @param  (Closure(Model): string)|null  $description  what the approver should know (the order's credit check, say) */
    public static function approve(?Closure $description = null): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record) => $description ? $description($record) : self::progressText($record))
            ->visible(fn (Model $record) => app(ApprovalEngine::class)->canApprove($record, auth()->user()))
            ->action(function (Model $record): void {
                try {
                    $complete = app(ApprovalEngine::class)->approve($record, auth()->user());
                    Notification::make()
                        ->title($complete ? __(':number approved', ['number' => $record->getAttribute('number')]) : __('Approval recorded; :number waits for the other approvers', ['number' => $record->getAttribute('number')]))
                        ->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title(__('Cannot approve'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
            ->visible(fn (Model $record) => app(ApprovalEngine::class)->canReject($record, auth()->user()))
            ->action(function (Model $record, array $data): void {
                try {
                    app(ApprovalEngine::class)->reject($record, auth()->user(), $data['reason']);
                    Notification::make()->title(__(':number rejected', ['number' => $record->getAttribute('number')]))->warning()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title(__('Cannot reject'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** The approval badge; hidden until the document is anything but plainly approved, so lists without approvals stay quiet. */
    public static function column(): TextColumn
    {
        return TextColumn::make('approval')
            ->label(__('Approval'))
            ->badge()
            ->state(fn (Model $record): string => app(ApprovalEngine::class)->status($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                ApprovalRequest::AWAITING => __('Awaiting approval'),
                ApprovalRequest::REJECTED => __('Rejected'),
                default => __('Approved'),
            })
            ->color(fn (string $state): string => match ($state) {
                ApprovalRequest::APPROVED => 'success',
                ApprovalRequest::REJECTED => 'danger',
                default => 'warning',
            })
            ->tooltip(fn (Model $record): ?string => self::progressText($record) ?: null)
            ->toggleable();
    }

    private static function progressText(Model $record): string
    {
        $progress = app(ApprovalEngine::class)->progress($record);
        $parts = [];
        if ($progress['done'] !== []) {
            $parts[] = __('Approved by :names.', ['names' => implode(', ', $progress['done'])]);
        }
        if ($progress['waiting'] !== []) {
            $parts[] = __('Waiting for :names.', ['names' => implode(', ', $progress['waiting'])]);
        }

        return implode(' ', $parts);
    }
}
