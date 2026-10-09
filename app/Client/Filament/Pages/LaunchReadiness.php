<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Ops\Launch\AttestationRecorder;
use App\Client\Domain\Ops\Launch\LaunchCheck;
use App\Client\Domain\Ops\Launch\LaunchReadiness as Readiness;
use App\Client\Screens\CentralScreen;
use App\Filament\Support\ErpPage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Launch Readiness: what still stands between the system and going live.
 * Two kinds of row, kept apart: what the system checked (no button; fix the
 * cause and the row goes green) and what a person attests (their name, the
 * date and the evidence they named).
 */
class LaunchReadiness extends ErpPage
{
    protected string $view = 'client.pages.launch-readiness';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::LaunchReadiness;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }
        $outstanding = app(Readiness::class)->outstanding();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return list<LaunchCheck> */
    public function checks(): array
    {
        return app(Readiness::class)->checks();
    }

    public function outstanding(): int
    {
        return app(Readiness::class)->outstanding();
    }

    public function attestAction(): Action
    {
        return Action::make('attest')->label(__('Attest'))->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn () => static::canUpdate() && (auth()->user()?->isAdministrator() ?? false))
            ->modalHeading(__('Attest this item'))
            ->modalDescription(__('The system cannot check this one, so what is recorded is your name and the date. Write the evidence: a PB-UMKU number, a lawyer\'s name, the date of the restore drill.'))
            ->schema([TextInput::make('note')->label(__('Evidence or reference'))->required()->maxLength(300)])
            ->action(function (array $arguments, array $data): void {
                try {
                    app(AttestationRecorder::class)->attest((string) $arguments['key'], auth()->user(), (string) $data['note']);
                    Notification::make()->title(__('Recorded'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('Cannot attest'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public function retractAction(): Action
    {
        return Action::make('retract')->label(__('Withdraw'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
            ->visible(fn () => static::canUpdate() && (auth()->user()?->isAdministrator() ?? false))
            ->modalHeading(__('Withdraw this attestation'))
            ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
            ->action(function (array $arguments, array $data): void {
                try {
                    app(AttestationRecorder::class)->retract((string) $arguments['key'], auth()->user(), (string) $data['reason']);
                    Notification::make()->title(__('Withdrawn'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('Cannot withdraw'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
