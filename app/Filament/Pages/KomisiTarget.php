<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Role;
use App\Domain\Komisi\KomisiSetter;
use App\Filament\Navigation\SidebarGroups;
use App\Models\SalesTarget;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The Owner's levers: what each seller earns per rupiah collected, and what
 * each sales seat is expected to bring in this month.
 *
 * Rates are effective-dated and append-only — the screen only ever adds a
 * new "from this date" row, so past months keep their arithmetic. Targets
 * replace per month, audited with old and new. The Komisi report under
 * Laporan reads both; this screen is where they change, and only here.
 */
class KomisiTarget extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    protected static ?string $navigationLabel = 'Komisi & target';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'komisi-target';

    protected string $view = 'filament.pages.komisi-target';

    public string $bulan = '';

    public function mount(): void
    {
        $this->bulan = Carbon::now()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Komisi & target';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role() === Role::Owner;
    }

    /**
     * Every seat that can earn, with its current rate and this month's target.
     *
     * @return Collection<int, array{user: User, tarif: int, target: ?int}>
     */
    public function kursi(): Collection
    {
        $setter = app(KomisiSetter::class);
        [$tahun, $bulanKe] = $this->bulanTerpilih();

        $targets = SalesTarget::query()
            ->where('tahun', $tahun)->where('bulan', $bulanKe)
            ->get()->keyBy('user_id');

        return User::query()
            ->whereIn('role', [Role::Sales, Role::Marketing])
            ->where('is_active', true)
            ->orderBy('role')->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'user' => $user,
                'tarif' => $setter->currentRate($user),
                'target' => $user->role() === Role::Sales
                    ? ($targets->get($user->id)?->target_rupiah)
                    : null,
            ]);
    }

    /** @return array{0: int, 1: int} */
    public function bulanTerpilih(): array
    {
        $bulan = Carbon::createFromFormat('Y-m', $this->bulan ?: Carbon::now()->format('Y-m'));

        return [(int) $bulan->year, (int) $bulan->month];
    }

    public function ubahTarifAction(): Action
    {
        return Action::make('ubahTarif')
            ->label('Ubah tarif')
            ->size('xs')
            ->color('gray')
            ->modalHeading('Tarif komisi baru')
            ->modalDescription('Tarif lama tidak dihapus — laporan bulan lampau tetap memakai '
                .'tarif yang berlaku saat itu.')
            ->schema([
                TextInput::make('persen')
                    ->label('Tarif (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step('0.01')
                    ->required()
                    ->suffix('%')
                    ->helperText('Dari dasar tanpa PPN, atas faktur yang lunas.'),

                DatePicker::make('berlaku_mulai')
                    ->label('Berlaku mulai')
                    ->default(now()->startOfMonth())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
            ])
            ->action(function (array $data, array $arguments) {
                $seat = User::query()->find($arguments['user'] ?? 0);

                if ($seat === null) {
                    return;
                }

                $this->jalankan(fn () => app(KomisiSetter::class)->setRate(
                    $seat,
                    (int) round(((float) $data['persen']) * 100),
                    Carbon::parse($data['berlaku_mulai']),
                    auth()->user(),
                ), "Tarif {$seat->name} diubah");
            });
    }

    public function aturTargetAction(): Action
    {
        return Action::make('aturTarget')
            ->label('Atur target')
            ->size('xs')
            ->color('primary')
            ->modalHeading(fn (): string => 'Target bulan '.$this->bulan)
            ->schema([
                TextInput::make('target_rupiah')
                    ->label('Target penjualan (tanpa PPN)')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('Rp'),
            ])
            ->action(function (array $data, array $arguments) {
                $seat = User::query()->find($arguments['user'] ?? 0);

                if ($seat === null) {
                    return;
                }

                [$tahun, $bulanKe] = $this->bulanTerpilih();

                $this->jalankan(fn () => app(KomisiSetter::class)->setTarget(
                    $seat,
                    $tahun,
                    $bulanKe,
                    (int) $data['target_rupiah'],
                    auth()->user(),
                ), "Target {$seat->name} dipasang");
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
