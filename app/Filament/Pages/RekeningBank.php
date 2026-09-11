<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Role;
use App\Domain\Banking\BankAccounts;
use App\Filament\Navigation\SidebarGroups;
use App\Models\BankAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The rekening the business runs — each with its own GL account, its own
 * balance line on the neraca, and its own reconciliation desk.
 *
 * Owner only. Opening one mints the GL account beside the original Bank;
 * the default is where money lands when nobody chooses, and where the
 * low-volume flows (beban, uang muka, aktiva) always pay from.
 */
class RekeningBank extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    protected static ?string $navigationLabel = 'Rekening bank';

    protected static ?int $navigationSort = 94;

    protected static ?string $slug = 'rekening-bank';

    protected string $view = 'filament.pages.rekening-bank';

    public function getTitle(): string
    {
        return 'Rekening bank';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role() === Role::Owner;
    }

    /** @return Collection<int, BankAccount> */
    public function daftar(): Collection
    {
        return BankAccount::query()->with('account')->orderByDesc('is_default')->orderBy('id')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('buka')
                ->label('Buka rekening')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->modalDescription(
                    'Rekening baru mendapat akun buku besar sendiri (1-11xx), tampil '
                    .'terpisah di neraca, dan direkonsiliasi terhadap rekening korannya '
                    .'sendiri.'
                )
                ->schema([
                    TextInput::make('nama')->label('Label')->required()->maxLength(60)
                        ->placeholder('mis. BCA operasional'),
                    TextInput::make('bank')->label('Bank')->required()->maxLength(30),
                    TextInput::make('nomor')->label('Nomor rekening')->required()->maxLength(40),
                    TextInput::make('atas_nama')->label('Atas nama')->maxLength(120),
                ])
                ->action(function (array $data) {
                    $this->jalankan(fn () => app(BankAccounts::class)->open(
                        $data['nama'], $data['bank'], $data['nomor'],
                        $data['atas_nama'] ?? '', auth()->user(),
                    ), 'Rekening dibuka');
                }),
        ];
    }

    public function jadikanBawaanAction(): Action
    {
        return Action::make('jadikanBawaan')
            ->label('Jadikan bawaan')
            ->size('xs')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Jadikan rekening bawaan')
            ->modalDescription('Uang yang dicatat tanpa memilih rekening akan masuk ke sini mulai sekarang.')
            ->action(function (array $arguments) {
                $rekening = BankAccount::query()->find($arguments['rekening'] ?? 0);

                if ($rekening === null) {
                    return;
                }

                $this->jalankan(fn () => app(BankAccounts::class)
                    ->setDefault($rekening, auth()->user()), 'Rekening bawaan dipindah');
            });
    }

    private function jalankan(callable $callback, string $sukses): void
    {
        try {
            $callback();

            Notification::make()->title($sukses)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Tidak bisa')->body($e->getMessage())->danger()->send();
        }
    }
}
