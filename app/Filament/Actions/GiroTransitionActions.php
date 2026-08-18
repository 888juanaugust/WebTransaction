<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Giro\GiroDirection;
use App\Domain\Giro\GiroRegister;
use App\Domain\Money;
use App\Models\Giro;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What can be done to a giro, and when.
 *
 * Four transitions, each hiding itself unless it applies — the same shape the
 * order screen uses. A row of buttons where three of them will refuse teaches
 * people to click hopefully, which is the opposite of what a screen that moves
 * money should teach.
 *
 * "Cair" is deliberately the least prominent despite being the happy ending.
 * It is the one that cannot be undone from here: it records a payment, and
 * unwinding that means reversing the payment on the invoice. Bouncing is
 * reversible in the sense that nothing was ever paid.
 */
class GiroTransitionActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            static::setor(),
            static::cair(),
            static::tolak(),
            static::batal(),
        ];
    }

    /**
     * Banked. No money moves and no journal is written — this records that the
     * paper has left the drawer, which is the difference between waiting on a
     * bank and forgetting to go.
     */
    public static function setor(string $name = 'setor'): Action
    {
        return Action::make($name)
            ->label('Setor ke bank')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('gray')
            ->schema([
                DatePicker::make('tanggal')
                    ->label('Tanggal setor')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
            ])
            /*
             * The due date is part of the visibility, not just something the
             * register refuses. A giro cannot be banked before the date
             * printed on it, and offering the button anyway is exactly the
             * "click and hope" this class exists to avoid — the refusal would
             * be correct and the screen would still have lied.
             */
            ->visible(fn (Giro $record) => static::mayHandle()
                && $record->isOpen()
                && $record->arah === GiroDirection::Masuk
                && $record->tanggal_setor === null
                && $record->isDue())
            ->action(fn (Giro $record, array $data) => static::run(
                fn () => app(GiroRegister::class)
                    ->markDeposited($record, auth()->user(), Carbon::parse($data['tanggal'])),
                "Giro {$record->nomor_warkat} dicatat sudah disetor",
                'Belum ada uang masuk. Catat "Cair" kalau bank sudah mengkredit rekening.',
            ));
    }

    /** It cleared. The only transition where money actually moves. */
    public static function cair(string $name = 'cair'): Action
    {
        return Action::make($name)
            ->label('Cair')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Giro cair')
            ->modalDescription(fn (Giro $record) => sprintf(
                '%s dari %s dicatat sebagai pembayaran %s. %s',
                Money::format((int) $record->nilai_rupiah),
                $record->counterpartyName(),
                $record->documentNumber() !== null
                    ? 'atas '.$record->documentNumber()
                    : 'yang belum dicocokkan ke dokumen mana pun',
                'Pastikan uangnya benar-benar sudah masuk atau keluar rekening.',
            ))
            ->modalSubmitActionLabel('Catat cair')
            ->schema([
                DatePicker::make('tanggal')
                    ->label('Tanggal cair')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
            ])
            ->visible(fn (Giro $record) => static::mayHandle() && $record->isOpen())
            ->action(fn (Giro $record, array $data) => static::run(
                fn () => app(GiroRegister::class)
                    ->clear($record, auth()->user(), Carbon::parse($data['tanggal'])),
                "Giro {$record->nomor_warkat} cair",
                $record->documentNumber() !== null
                    ? 'Pembayaran tercatat atas '.$record->documentNumber().'.'
                    : 'Pembayaran tercatat dan menunggu dicocokkan ke faktur.',
            ));
    }

    /**
     * It bounced.
     *
     * The reason is required and it is not paperwork: "saldo tidak cukup" and
     * "rekening ditutup" are different futures for the relationship, and six
     * months later nobody remembers which one it was.
     */
    public static function tolak(string $name = 'tolak'): Action
    {
        return Action::make($name)
            ->label('Tolak')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Giro ditolak bank')
            ->modalDescription(
                'Utangnya kembali seperti semula — tidak ada pembayaran yang perlu dibatalkan, '
                .'karena memang belum pernah ada.'
            )
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan dari bank')
                    ->required()
                    ->rows(2)
                    ->placeholder('mis. saldo tidak cukup')
                    ->helperText(
                        'Tulis apa adanya. "Saldo tidak cukup" dan "rekening ditutup" '
                        .'artinya sangat berbeda untuk hubungan dagang ke depan.'
                    ),

                DatePicker::make('tanggal')
                    ->label('Tanggal tolak')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
            ])
            ->visible(fn (Giro $record) => static::mayHandle() && $record->isOpen())
            ->action(fn (Giro $record, array $data) => static::run(
                fn () => app(GiroRegister::class)->bounce(
                    $record,
                    auth()->user(),
                    $data['alasan'],
                    Carbon::parse($data['tanggal']),
                ),
                "Giro {$record->nomor_warkat} ditolak",
                'Tagihannya terbuka lagi dan plafon kreditnya tetap terpakai.',
            ));
    }

    /** Handed back uncashed. Not a failure, and not a payment. */
    public static function batal(string $name = 'batal'): Action
    {
        return Action::make($name)
            ->label('Kembalikan')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading('Giro dikembalikan')
            ->modalDescription(
                'Untuk giro yang tidak jadi dipakai — pelanggan bayar tunai, atau gironya salah tulis '
                .'dan akan diganti. Bukan penolakan bank.'
            )
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->rows(2)
                    ->placeholder('mis. pelanggan bayar transfer, giro dikembalikan'),

                DatePicker::make('tanggal')
                    ->label('Tanggal')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
            ])
            ->visible(fn (Giro $record) => static::mayHandle() && $record->isOpen())
            ->action(fn (Giro $record, array $data) => static::run(
                fn () => app(GiroRegister::class)->cancel(
                    $record,
                    auth()->user(),
                    $data['alasan'],
                    Carbon::parse($data['tanggal']),
                ),
                "Giro {$record->nomor_warkat} dikembalikan",
                'Tagihannya kembali seperti sebelum giro diterima.',
            ));
    }

    private static function mayHandle(): bool
    {
        return auth()->user()?->role()->canHandleGiro() ?? false;
    }

    /**
     * Run a transition and say what happened, either way.
     *
     * Every refusal in GiroRegister is a sentence written for the person
     * reading it, so it goes on the screen as it is rather than being
     * flattened into "something went wrong".
     */
    private static function run(callable $transition, string $title, string $body): void
    {
        try {
            $transition();
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
