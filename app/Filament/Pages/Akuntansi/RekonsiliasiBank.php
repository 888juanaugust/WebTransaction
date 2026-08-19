<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Accounting\AccountCode;
use App\Domain\Banking\BankReconciler;
use App\Domain\Banking\ReconciliationSummary;
use App\Domain\Banking\StatementDirection;
use App\Domain\Money;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ticking the bank statement off against the books.
 *
 * A working screen rather than a register: the thing somebody does here is sit
 * with a statement in one hand and tick lines with the other, and the screen is
 * built around that hour rather than around the document it produces.
 *
 * Three things are on it at once, deliberately. The **running difference** at
 * the top, because it is the only number that matters and watching it fall to
 * nil is the whole feedback loop. The **lines to tick**, in date order, because
 * that is the order a statement is printed in. And the **items** — what the
 * statement has that the books do not — because finding the difference is
 * useless without somewhere to put it.
 *
 * Only one reconciliation is ever in progress, so the page opens on the draft
 * if there is one and otherwise offers to start it. A list of past
 * reconciliations would be a filing cabinet; what is wanted is the desk.
 */
class RekonsiliasiBank extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static \UnitEnum|string|null $navigationGroup = 'Buku besar';

    protected static ?string $navigationLabel = 'Rekonsiliasi bank';

    protected static ?int $navigationSort = 76;

    protected static ?string $slug = 'akuntansi/rekonsiliasi-bank';

    protected string $view = 'filament.pages.akuntansi.rekonsiliasi-bank';

    public function getTitle(): string
    {
        return 'Rekonsiliasi bank';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canReconcileBank() ?? false;
    }

    /**
     * How long the bank has gone unproven, for the sidebar.
     *
     * An unreconciled bank account is invisible: nothing breaks, no queue
     * fills, every report still renders. The badge is the only thing that ever
     * mentions it, so it appears at five weeks — a month plus the few days a
     * statement takes to arrive — and says "never" rather than a number when
     * it has never been done at all.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $days = app(BankReconciler::class)->daysSinceLastReconciled();

        if ($days === null) {
            return 'belum pernah';
        }

        return $days > 35 ? $days.' hari' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function currentReconciliation(): ?BankReconciliation
    {
        return BankReconciliation::query()
            ->where('status', BankReconciliation::STATUS_DRAFT)
            ->orderByDesc('tanggal_rekening')
            ->first();
    }

    public function summary(): ?ReconciliationSummary
    {
        $current = $this->currentReconciliation();

        return $current === null ? null : app(BankReconciler::class)->summarise($current);
    }

    /**
     * The lines to tick, each with whether it is ticked and what it was.
     *
     * `keterangan` comes from the entry rather than the line's own memo: what
     * somebody is matching against a statement is "payment from CV Sinar", and
     * the line memo on a Bank leg is usually a reference number nobody reads.
     *
     * @return Collection<int, array{line: JournalLine, ticked: bool, keterangan: string}>
     */
    public function lines(): Collection
    {
        $current = $this->currentReconciliation();

        if ($current === null) {
            return collect();
        }

        $reconciler = app(BankReconciler::class);
        $ticked = $reconciler->tickedLineIds($current);

        return $reconciler->candidateLines($current)->map(fn (JournalLine $line) => [
            'line' => $line,
            'ticked' => isset($ticked[$line->id]),
            'keterangan' => $line->entry?->keterangan ?? '—',
        ]);
    }

    /** Toggle one line. Called straight from the checkbox. */
    public function toggle(int $lineId): void
    {
        $current = $this->currentReconciliation();

        if ($current === null) {
            return;
        }

        $line = JournalLine::query()->find($lineId);

        if ($line === null) {
            return;
        }

        $reconciler = app(BankReconciler::class);

        try {
            if (isset($reconciler->tickedLineIds($current)[$line->id])) {
                $reconciler->untick($current, $line, auth()->user());
            } else {
                $reconciler->tick($current, $line, auth()->user());
            }
        } catch (Throwable $e) {
            Notification::make()
                ->title('Tidak bisa dicentang')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->mulaiAction(),
            $this->centangSemuaAction(),
            $this->tambahItemAction(),
            $this->selesaikanAction(),
            $this->batalkanAction(),
        ];
    }

    /** Start one. Two questions: which statement, and what it closes at. */
    private function mulaiAction(): Action
    {
        return Action::make('mulai')
            ->label('Mulai rekonsiliasi')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => $this->currentReconciliation() === null)
            ->schema([
                DatePicker::make('tanggal_rekening')
                    ->label('Tanggal rekening koran')
                    ->default(now()->subMonthNoOverflow()->endOfMonth())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->required()
                    ->helperText('Tanggal penutupan yang tercetak di rekening koran.'),

                TextInput::make('saldo_rekening_rupiah')
                    ->label('Saldo akhir di rekening koran')
                    ->numeric()
                    ->required()
                    ->prefix('Rp')
                    ->helperText(
                        'Ketik apa adanya dari rekening koran. Ini satu-satunya angka '
                        .'di seluruh sistem yang datang dari luar, dan itulah gunanya.'
                    ),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                $this->run(
                    fn () => app(BankReconciler::class)->open(
                        Carbon::parse($data['tanggal_rekening']),
                        (int) $data['saldo_rekening_rupiah'],
                        auth()->user(),
                        $data['catatan'] ?: null,
                    ),
                    'Rekonsiliasi dimulai',
                    'Centang setiap baris yang muncul di rekening koran.',
                );
            });
    }

    private function centangSemuaAction(): Action
    {
        return Action::make('centangSemua')
            ->label('Centang semua')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Centang semua baris')
            ->modalDescription(
                'Untuk bulan yang bersih, di mana semuanya sudah masuk rekening koran. '
                .'Yang ternyata belum masuk tinggal dibuka centangnya satu per satu.'
            )
            ->visible(fn () => $this->currentReconciliation() !== null)
            ->action(function () {
                $current = $this->currentReconciliation();

                $this->run(
                    fn () => app(BankReconciler::class)->tickAll($current, auth()->user()),
                    'Semua baris dicentang',
                    'Buka centang yang belum muncul di rekening koran.',
                );
            });
    }

    /**
     * Record something the statement has and the books do not.
     *
     * The account list is deliberately not the whole chart. A statement item
     * is a bank fee, interest, or a transfer against a known balance — offering
     * two hundred accounts invites somebody to book a bank charge to Persediaan
     * because it was near the top of the list.
     */
    private function tambahItemAction(): Action
    {
        return Action::make('tambahItem')
            ->label('Tambah item rekening koran')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('gray')
            ->visible(fn () => $this->currentReconciliation() !== null)
            ->modalDescription(
                'Yang ada di rekening koran tapi belum ada di buku: biaya bank, bunga, '
                .'transfer yang belum tercatat. Ini langsung menjadi jurnal, karena '
                .'banknya memang sudah melakukannya.'
            )
            ->schema([
                TextInput::make('keterangan')
                    ->label('Keterangan')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('mis. biaya administrasi Agustus'),

                Select::make('arah')
                    ->label('Arah')
                    ->options([
                        StatementDirection::Keluar->value => 'Uang keluar (biaya, potongan)',
                        StatementDirection::Masuk->value => 'Uang masuk (bunga, transfer)',
                    ])
                    ->default(StatementDirection::Keluar->value)
                    ->required(),

                TextInput::make('amount_rupiah')
                    ->label('Nilai')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                Select::make('account')
                    ->label('Lawan jurnal')
                    ->options(fn () => static::contraAccounts())
                    ->default(AccountCode::BEBAN_ADMIN_BANK)
                    ->required()
                    ->helperText('Ke mana sisi satunya masuk.'),

                DatePicker::make('tanggal')
                    ->label('Tanggal di rekening koran')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(fn () => $this->currentReconciliation()?->tanggal_rekening),
            ])
            ->action(function (array $data) {
                $current = $this->currentReconciliation();

                $this->run(
                    fn () => app(BankReconciler::class)->recordStatementItem(
                        $current,
                        $data['keterangan'],
                        (int) $data['amount_rupiah'],
                        StatementDirection::from($data['arah']),
                        $data['account'],
                        auth()->user(),
                        $data['tanggal'] ? Carbon::parse($data['tanggal']) : null,
                    ),
                    'Item dicatat',
                    'Jurnalnya sudah diposting dan barisnya otomatis tercentang.',
                );
            });
    }

    /**
     * Sign it off.
     *
     * Shown the whole time and disabled while a difference stands, rather than
     * hidden: somebody an hour into a reconciliation needs to see what they are
     * working towards. What it must not do is open a confirmation promising to
     * freeze the figures and then refuse — the reconciler would refuse anyway,
     * but by then the person has been told twice that this was going to work.
     */
    private function selesaikanAction(): Action
    {
        return Action::make('selesaikan')
            ->label('Selesaikan')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Selesaikan rekonsiliasi')
            ->modalDescription(
                'Angkanya dibekukan seperti sekarang dan tidak bisa diubah lagi. '
                .'Baris yang belum dicentang tetap terbuka untuk bulan depan.'
            )
            ->visible(fn () => $this->currentReconciliation() !== null)
            ->disabled(fn () => ! ($this->summary()?->isReconciled() ?? false))
            ->tooltip(function () {
                $summary = $this->summary();

                return $summary === null || $summary->isReconciled()
                    ? null
                    : sprintf(
                        'Masih ada selisih %s yang belum dijelaskan.',
                        Money::format(abs($summary->selisih)),
                    );
            })
            ->action(function () {
                $current = $this->currentReconciliation();

                $this->run(
                    fn () => app(BankReconciler::class)->finalise($current, auth()->user()),
                    "Rekonsiliasi {$current->nomor} selesai",
                    'Saldo bank sudah dibuktikan terhadap rekening koran.',
                );
            });
    }

    /**
     * Throw away a draft.
     *
     * Its ticks go with it, which is what makes this safe: a draft has changed
     * nothing except which lines it claimed, and deleting it hands them all
     * back. Statement items it posted stay — those are real transactions the
     * bank carried out, and they do not become untrue because somebody
     * abandoned the reconciliation.
     */
    private function batalkanAction(): Action
    {
        return Action::make('batalkan')
            ->label('Buang draf')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Buang draf rekonsiliasi')
            ->modalDescription(
                'Centangnya hilang dan barisnya bisa direkonsiliasi lagi. Item rekening '
                .'koran yang sudah dicatat tetap ada — banknya sudah terlanjur melakukannya.'
            )
            ->visible(fn () => $this->currentReconciliation() !== null)
            ->action(function () {
                $current = $this->currentReconciliation();
                $nomor = $current->nomor;

                $current->delete();

                Notification::make()
                    ->title("Draf {$nomor} dibuang")
                    ->body('Barisnya kembali bisa direkonsiliasi.')
                    ->success()
                    ->send();
            });
    }

    /** Reconciliations already signed off, newest first. */
    public function history(): Collection
    {
        return BankReconciliation::query()
            ->finalised()
            ->with('finalisedBy')
            ->orderByDesc('tanggal_rekening')
            ->limit(12)
            ->get();
    }

    public function daysSince(): ?int
    {
        return app(BankReconciler::class)->daysSinceLastReconciled();
    }

    /**
     * The handful of accounts a bank statement item ever belongs to.
     *
     * @return array<string, string>
     */
    private static function contraAccounts(): array
    {
        $codes = [
            // Ordered by how often a statement line turns out to be each one.
            AccountCode::BEBAN_ADMIN_BANK,
            AccountCode::PENDAPATAN_LAIN,
            AccountCode::PIUTANG_USAHA,
            AccountCode::UTANG_USAHA,
            AccountCode::KAS,
            AccountCode::BEBAN_OPERASIONAL,
        ];

        return Account::query()
            ->whereIn('kode', $codes)
            ->orderBy('kode')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->kode => "{$a->kode} — {$a->nama}"])
            ->all();
    }

    private function run(callable $action, string $title, string $body): void
    {
        try {
            $action();
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
