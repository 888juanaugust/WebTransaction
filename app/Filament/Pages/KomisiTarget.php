<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Role;
use App\Domain\Komisi\JenisKomisi;
use App\Domain\Komisi\KomisiSetter;
use App\Filament\Navigation\SidebarGroups;
use App\Models\Region;
use App\Models\SalesTarget;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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

    protected static ?int $navigationSort = 93;

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
            ->where('jenis', JenisKomisi::Penjualan->value)
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

    /**
     * The three other kinds: every (person, kind, cabang) with a rate, its
     * current rate and this month's target.
     *
     * @return Collection<int, array{user: User, jenis: JenisKomisi, region: ?Region, tarif: int, target: ?int}>
     */
    public function lainnya(): Collection
    {
        $setter = app(KomisiSetter::class);
        [$tahun, $bulanKe] = $this->bulanTerpilih();

        $targets = SalesTarget::query()
            ->where('tahun', $tahun)->where('bulan', $bulanKe)
            ->where('jenis', '!=', JenisKomisi::Penjualan->value)
            ->get()->keyBy(fn (SalesTarget $t) => $t->user_id.'|'.$t->jenis);

        return $setter->lainnya()->map(fn (array $row) => [
            ...$row,
            'tarif' => $setter->currentRate($row['user'], $row['jenis'], $row['region']?->id),
            'target' => $targets->get($row['user']->id.'|'.$row['jenis']->value)?->target_rupiah,
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

                $jenis = JenisKomisi::tryFrom((string) ($arguments['jenis'] ?? '')) ?? JenisKomisi::Penjualan;
                $region = isset($arguments['region']) ? (int) $arguments['region'] : null;

                $this->jalankan(fn () => app(KomisiSetter::class)->setRate(
                    $seat,
                    (int) round(((float) $data['persen']) * 100),
                    Carbon::parse($data['berlaku_mulai']),
                    auth()->user(),
                    $jenis,
                    $region,
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
                    ->label('Target dasar bulan ini (tanpa PPN)')
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
                $jenis = JenisKomisi::tryFrom((string) ($arguments['jenis'] ?? '')) ?? JenisKomisi::Penjualan;

                $this->jalankan(fn () => app(KomisiSetter::class)->setTarget(
                    $seat,
                    $tahun,
                    $bulanKe,
                    (int) $data['target_rupiah'],
                    auth()->user(),
                    $jenis,
                ), "Target {$seat->name} dipasang");
            });
    }

    /**
     * Give somebody one of the three other kinds: supervisor of a cabang,
     * manajer, or the import purchasing commission.
     */
    public function tambahKomisiAction(): Action
    {
        return Action::make('tambahKomisi')
            ->label('Tambah komisi supervisor / manajer / impor')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Komisi jenis lain')
            ->modalDescription('Supervisor dibayar atas seluruh faktur lunas satu cabang, Manajer atas '
                .'semua cabang, Pembelian impor atas tagihan pemasok lunas untuk barang golongan impor. '
                .'Semuanya tanpa PPN, dan hanya atas uang yang benar-benar masuk atau keluar.')
            ->schema([
                Select::make('user_id')
                    ->label('Orang')
                    ->options(fn () => User::query()
                        ->where('is_active', true)
                        ->where('role', '!=', Role::Owner->value)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} — {$u->role()->label()}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false),

                Select::make('jenis')
                    ->label('Jenis')
                    ->options(collect(JenisKomisi::cases())
                        ->reject(fn (JenisKomisi $j) => $j->perKursi())
                        ->mapWithKeys(fn (JenisKomisi $j) => [$j->value => $j->label().' — '.$j->dasar()])
                        ->all())
                    ->required()
                    ->live()
                    ->native(false),

                Select::make('cabang_id')
                    ->label('Cabang')
                    ->options(fn () => Region::query()->where('aktif', true)->orderBy('kode')->get()
                        ->mapWithKeys(fn (Region $r) => [$r->id => $r->label()])->all())
                    ->visible(fn (callable $get) => $get('jenis') === JenisKomisi::Supervisor->value)
                    ->required(fn (callable $get) => $get('jenis') === JenisKomisi::Supervisor->value)
                    ->native(false),

                TextInput::make('persen')
                    ->label('Tarif (%)')
                    ->numeric()->minValue(0)->maxValue(100)->step('0.01')
                    ->required()->suffix('%'),

                DatePicker::make('berlaku_mulai')
                    ->label('Berlaku mulai')
                    ->default(now()->startOfMonth())
                    ->native(false)->displayFormat('d/m/Y')
                    ->required(),
            ])
            ->action(function (array $data) {
                $seat = User::query()->find($data['user_id'] ?? 0);
                $jenis = JenisKomisi::tryFrom((string) ($data['jenis'] ?? ''));

                if ($seat === null || $jenis === null) {
                    return;
                }

                $this->jalankan(fn () => app(KomisiSetter::class)->setRate(
                    $seat,
                    (int) round(((float) $data['persen']) * 100),
                    Carbon::parse($data['berlaku_mulai']),
                    auth()->user(),
                    $jenis,
                    $jenis->butuhCabang() ? (int) ($data['cabang_id'] ?? 0) : null,
                ), "Komisi {$jenis->label()} untuk {$seat->name} dipasang");
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->tambahKomisiAction()];
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
