<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Access\StaffRegistrar;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The three things done to an account that are not editing a field.
 *
 * Each one hides itself unless it applies, the same shape the order and giro
 * screens use: a row of buttons where half of them will refuse teaches people
 * to click hopefully.
 *
 * There is no delete here, and its absence is the design. The database agrees
 * — `audit_logs.actor_id` references `users` with ON DELETE NO ACTION — so
 * removing somebody who ever did anything would either fail outright or take
 * the record of what they did with it. A leaver gets switched off; the trail
 * of what they did stays readable, which is the point of keeping one.
 */
class StaffAccountActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            static::setelSandi(),
            static::nonaktifkan(),
            static::aktifkanLagi(),
        ];
    }

    /**
     * Set somebody's password because they cannot get in to do it themselves.
     *
     * A worse mechanism than a reset link, and it is here because the better
     * one needs working mail this deployment does not have yet. Its weakness
     * is inherent: for a moment the owner knows a colleague's password. The
     * modal says so, because a person told to pass it on and have it changed
     * behaves differently from one who thinks they have set it permanently.
     */
    public static function setelSandi(string $name = 'setelSandi'): Action
    {
        return Action::make($name)
            ->label('Setel sandi')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->modalHeading('Setel sandi baru')
            ->modalDescription('Untuk staf yang lupa sandi atau tidak bisa masuk. Sampaikan sandi '
                .'ini langsung ke orangnya dan minta diganti sendiri lewat menu profil — '
                .'selama belum diganti, sandinya juga Anda ketahui.')
            ->modalSubmitActionLabel('Setel sandi')
            ->schema([
                TextInput::make('password')
                    ->label('Sandi baru')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(12)
                    ->helperText('Minimal 12 karakter.'),
            ])
            ->visible(fn () => static::mayManage())
            ->action(fn (User $record, array $data) => static::run(
                fn () => app(StaffRegistrar::class)->setPassword($record, $data['password'], auth()->user()),
                "Sandi {$record->name} sudah disetel",
                'Sesi "ingat saya" yang lama ikut dibatalkan. Minta yang bersangkutan menggantinya sendiri.',
            ));
    }

    /** Somebody has left, or needs shutting off now. */
    public static function nonaktifkan(string $name = 'nonaktifkan'): Action
    {
        return Action::make($name)
            ->label('Nonaktifkan')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading('Nonaktifkan akun')
            ->modalDescription('Akunnya tidak dihapus — riwayat siapa mengerjakan apa tetap utuh. '
                .'Yang bersangkutan langsung tidak bisa masuk, termasuk kalau sedang membuka '
                .'halaman saat ini juga.')
            ->modalSubmitActionLabel('Nonaktifkan')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->rows(2)
                    ->placeholder('mis. mengundurkan diri per 31 Agustus'),
            ])
            /*
             * Hidden on your own row rather than shown and refused. This is the
             * one action whose accident has no way back from inside the app.
             */
            ->visible(fn (User $record) => static::mayManage()
                && $record->is_active
                && $record->getKey() !== auth()->id())
            ->action(fn (User $record, array $data) => static::run(
                fn () => app(StaffRegistrar::class)->deactivate($record, auth()->user(), $data['alasan']),
                "Akun {$record->name} dinonaktifkan",
                'Bisa diaktifkan lagi kapan saja dari layar ini.',
            ));
    }

    public static function aktifkanLagi(string $name = 'aktifkanLagi'): Action
    {
        return Action::make($name)
            ->label('Aktifkan lagi')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Aktifkan akun lagi')
            ->modalDescription('Sandinya tidak berubah. Kalau yang bersangkutan sudah lupa, '
                .'setel sandi baru setelah ini.')
            ->modalSubmitActionLabel('Aktifkan')
            ->visible(fn (User $record) => static::mayManage() && ! $record->is_active)
            ->action(fn (User $record) => static::run(
                fn () => app(StaffRegistrar::class)->reactivate($record, auth()->user()),
                "Akun {$record->name} aktif lagi",
                'Sudah bisa masuk dengan sandi yang lama.',
            ));
    }

    private static function mayManage(): bool
    {
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    /**
     * Every refusal in StaffRegistrar is a sentence written for the person
     * reading it, so it goes on the screen as it is rather than being
     * flattened into "something went wrong".
     */
    private static function run(callable $tindakan, string $title, string $body): void
    {
        try {
            $tindakan();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Tidak bisa dilanjutkan')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title($title)->body($body)->success()->send();
    }
}
