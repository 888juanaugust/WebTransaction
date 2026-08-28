<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Expenses\ClaimStatus;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Expenses\SalesExpenseClaims;
use App\Domain\Money;
use App\Models\SalesExpenseClaim;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Finance's two buttons on an expedition-expense claim, shared by the
 * dashboard queue and the register so the screens cannot drift apart.
 */
class SalesExpenseClaimActions
{
    private static function mayDecide(SalesExpenseClaim $record): bool
    {
        return $record->status === ClaimStatus::Diajukan
            && (auth()->user()?->role()->canPostJournals() ?? false);
    }

    public static function setujui(string $name = 'setujui_klaim'): Action
    {
        return Action::make($name)
            ->label('Setujui')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Setujui biaya ekspedisi')
            ->modalDescription(fn (SalesExpenseClaim $record) => 'Menyetujui akan mencatat beban '
                .Money::format($record->amount_rupiah)
                .' ke akun ongkos kirim — masuk buku dan tidak bisa diedit, hanya bisa dibalik.')
            ->schema([
                Select::make('dibayar_dari')
                    ->label('Dibayar dari')
                    ->options(collect(PaidFrom::cases())
                        ->mapWithKeys(fn (PaidFrom $p) => [$p->value => $p->label()])
                        ->all())
                    ->default(PaidFrom::Kas->value)
                    ->required()
                    ->native(false),
                Textarea::make('catatan')
                    ->label('Catatan verifikasi')
                    ->helperText('Bagaimana pengeluarannya dicek — opsional, tercatat di klaim.')
                    ->maxLength(500),
            ])
            ->visible(fn (SalesExpenseClaim $record) => static::mayDecide($record))
            ->action(function (SalesExpenseClaim $record, array $data) {
                try {
                    app(SalesExpenseClaims::class)->approve(
                        $record,
                        auth()->user(),
                        PaidFrom::from($data['dibayar_dari']),
                        $data['catatan'] ?? null,
                    );

                    Notification::make()
                        ->title('Biaya ekspedisi disetujui')
                        ->body('Beban tercatat di buku.')
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa disetujui')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function tolak(string $name = 'tolak_klaim'): Action
    {
        return Action::make($name)
            ->label('Tolak')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Tolak biaya ekspedisi')
            ->schema([
                Textarea::make('catatan')
                    ->label('Kenapa ditolak')
                    ->helperText('Sales yang mengajukan akan membaca ini.')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (SalesExpenseClaim $record) => static::mayDecide($record))
            ->action(function (SalesExpenseClaim $record, array $data) {
                try {
                    app(SalesExpenseClaims::class)->reject($record, auth()->user(), $data['catatan']);

                    Notification::make()->title('Klaim ditolak')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa ditolak')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
