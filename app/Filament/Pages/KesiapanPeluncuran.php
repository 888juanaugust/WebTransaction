<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Launch\AttestationRecorder;
use App\Domain\Launch\LaunchCheck;
use App\Domain\Launch\LaunchReadiness;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * What is still in the way of going live.
 *
 * The same list as docs/DEPLOY.md, with one difference that is the whole
 * reason it exists as a screen: **most of it checks itself.** A checklist of
 * tickboxes is a worse version of the file, because a box nobody can verify
 * gets ticked on a Friday and stays ticked long after it stopped being true.
 *
 * So the tax NPWP, the price list, the seeded staff passwords, the off-box
 * backup and the control accounts are read from the system every time this page
 * loads, and no button on it can mark them done. Only the handful of things
 * nothing here can see — an OSS registration, a lawyer's reading, a rehearsed
 * restore — are somebody's word, and those carry a name and a date.
 *
 * Owner only. Deciding the business is cleared to trade is not a clerical act.
 */
class KesiapanPeluncuran extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    protected static ?string $navigationLabel = 'Kesiapan peluncuran';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'kesiapan-peluncuran';

    protected string $view = 'filament.pages.kesiapan-peluncuran';

    public function getTitle(): string
    {
        return 'Kesiapan peluncuran';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canViewAuditLog() ?? false;
    }

    /**
     * The badge is the count of what is still outstanding.
     *
     * It disappears when the list is clear, which is the only time this screen
     * has nothing to say.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $outstanding = app(LaunchReadiness::class)->outstanding();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return list<LaunchCheck> */
    public function checks(): array
    {
        return app(LaunchReadiness::class)->checks();
    }

    public function outstanding(): int
    {
        return app(LaunchReadiness::class)->outstanding();
    }

    /** Somebody putting their name to a thing the system cannot see. */
    public function nyatakanAction(): Action
    {
        return Action::make('nyatakan')
            ->label('Nyatakan sudah')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Nyatakan item ini sudah selesai')
            ->modalDescription(
                'Sistem tidak bisa memeriksa yang satu ini, jadi yang tercatat adalah '
                .'nama Anda dan tanggalnya. Tulis buktinya — nomor PB-UMKU, nama '
                .'pengacara, tanggal latihan pemulihan — supaya enam bulan lagi masih '
                .'ada yang bisa ditanyakan.'
            )
            ->schema([
                TextInput::make('catatan')
                    ->label('Bukti atau rujukan')
                    ->maxLength(300)
                    ->required()
                    ->placeholder('mis. PB-UMKU 91234567890123, terbit 14/07/2026'),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    app(AttestationRecorder::class)->attest(
                        kunci: $arguments['kunci'],
                        actor: auth()->user(),
                        catatan: $data['catatan'],
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dinyatakan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Tercatat')->success()->send();
            });
    }

    public function cabutAction(): Action
    {
        return Action::make('cabut')
            ->label('Cabut')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->modalHeading('Cabut pernyataan')
            ->modalDescription('Item ini kembali menjadi belum selesai. Catatan pencabutan '
                .'tersimpan di log audit.')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->rows(2)
                    ->placeholder('mis. Ternyata PB-UMKU belum terbit'),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    app(AttestationRecorder::class)->retract(
                        kunci: $arguments['kunci'],
                        actor: auth()->user(),
                        alasan: $data['alasan'],
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dicabut')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Pernyataan dicabut')->success()->send();
            });
    }
}
