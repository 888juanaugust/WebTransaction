<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Accounting\AccountCode;
use App\Domain\Banking\BankReconciler;
use App\Domain\Banking\ReconciliationSummary;
use App\Domain\Banking\StatementDirection;
use App\Domain\Banking\StatementImporter;
use App\Domain\Banking\StatementMatcher;
use App\Domain\Money;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalLine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
            $this->imporMutasiAction(),
            $this->cocokkanOtomatisAction(),
            $this->centangSemuaAction(),
            $this->tambahItemAction(),
            $this->selesaikanAction(),
            $this->batalkanAction(),
        ];
    }

    /**
     * The statement's own rows, with what the matcher proposes for each.
     *
     * @return Collection<int, array{line: BankStatementLine, candidates: Collection<int, JournalLine>}>
     */
    public function mutasi(): Collection
    {
        $current = $this->currentReconciliation();

        if ($current === null) {
            return collect();
        }

        $matcher = app(StatementMatcher::class);

        return $current->statementImports()->with('lines.journalLine.entry')->get()
            ->flatMap(function (BankStatementImport $import) use ($matcher) {
                $suggestions = $import->status === BankStatementImport::STATUS_SELESAI
                    ? $matcher->suggestions($import)
                    : [];

                return $import->lines->map(fn (BankStatementLine $line) => [
                    'line' => $line,
                    'candidates' => $suggestions[$line->id] ?? collect(),
                ]);
            });
    }

    /** @return Collection<int, BankStatementImport> */
    public function mutasiImports(): Collection
    {
        $current = $this->currentReconciliation();

        return $current === null
            ? collect()
            : $current->statementImports()->with('creator')->get();
    }

    /** Confirm one proposed match. Reversible with "lepas" while the draft lives. */
    public function cocokkan(int $lineId, int $journalLineId): void
    {
        $line = BankStatementLine::query()->find($lineId);
        $journalLine = JournalLine::query()->find($journalLineId);

        if ($line === null || $journalLine === null) {
            return;
        }

        $this->run(
            fn () => app(StatementMatcher::class)->confirm($line, $journalLine, auth()->user()),
            'Baris dicocokkan',
            'Baris jurnalnya ikut tercentang.',
        );
    }

    /** Take a match or an ignore back. */
    public function lepas(int $lineId): void
    {
        $line = BankStatementLine::query()->find($lineId);

        if ($line === null) {
            return;
        }

        $this->run(
            fn () => app(StatementMatcher::class)->reset($line, auth()->user()),
            'Cocokan dilepas',
            'Barisnya kembali menunggu.',
        );
    }

    /** Upload the statement file into the draft reconciliation. */
    private function imporMutasiAction(): Action
    {
        return Action::make('imporMutasi')
            ->label('Impor mutasi')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn () => $this->currentReconciliation() !== null)
            ->modalDescription(
                'CSV yang diekspor portal bank: kolom tanggal, uraian, dan mutasi '
                .'(debit/kredit terpisah, atau satu kolom jumlah dengan penanda DB/CR). '
                .'Berkas asli disimpan permanen.'
            )
            ->schema([
                FileUpload::make('berkas')
                    ->label('Berkas mutasi (CSV)')
                    ->required()
                    ->disk('local')
                    ->directory('mutasi-bank')
                    ->preserveFilenames()
                    ->acceptedFileTypes([
                        'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                    ]),
            ])
            ->action(function (array $data) {
                $current = $this->currentReconciliation();
                $path = $data['berkas'];

                $this->run(
                    function () use ($current, $path) {
                        $import = app(StatementImporter::class)->import(
                            $current,
                            $path,
                            basename((string) $path),
                            auth()->user(),
                        );

                        if ($import->status === BankStatementImport::STATUS_GAGAL) {
                            throw new \DomainException($import->catatan ?? 'Berkas tidak terbaca.');
                        }
                    },
                    'Mutasi terbaca',
                    'Cocokkan otomatis dulu, lalu selesaikan sisanya satu per satu.',
                );
            });
    }

    /** Apply every unambiguous suggestion in one pass. */
    private function cocokkanOtomatisAction(): Action
    {
        return Action::make('cocokkanOtomatis')
            ->label('Cocokkan otomatis')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Cocokkan otomatis')
            ->modalDescription(
                'Hanya baris yang calon pasangannya persis satu — nilai sama, arah sama, '
                .'selisih tanggal paling jauh tujuh hari. Yang punya dua kandidat dibiarkan '
                .'untuk dilihat orang.'
            )
            ->visible(fn () => $this->mutasiImports()
                ->where('status', BankStatementImport::STATUS_SELESAI)->isNotEmpty())
            ->action(function () {
                $current = $this->currentReconciliation();
                $matcher = app(StatementMatcher::class);

                $this->run(
                    function () use ($current, $matcher) {
                        $total = 0;

                        foreach ($current->statementImports()->get() as $import) {
                            if ($import->status === BankStatementImport::STATUS_SELESAI) {
                                $total += $matcher->autoMatch($import, auth()->user());
                            }
                        }

                        Notification::make()
                            ->title("{$total} baris tercocok otomatis")
                            ->body($total === 0
                                ? 'Tidak ada pasangan yang tegas — sisanya perlu mata orang.'
                                : 'Sisanya menunggu dilihat orang.')
                            ->info()
                            ->send();
                    },
                    'Pencocokan otomatis selesai',
                    'Baris dengan pasangan tegas sudah tercentang.',
                );
            });
    }

    /** Set a statement line aside, with the reason kept on it. */
    public function abaikanAction(): Action
    {
        return Action::make('abaikan')
            ->label('Abaikan')
            ->color('gray')
            ->size('xs')
            ->schema([
                TextInput::make('alasan')
                    ->label('Alasan')
                    ->placeholder('mis. sudah beres di rekonsiliasi manual')
                    ->maxLength(255),
            ])
            ->action(function (array $data, array $arguments) {
                $line = BankStatementLine::query()->find($arguments['line'] ?? 0);

                if ($line === null) {
                    return;
                }

                $this->run(
                    fn () => app(StatementMatcher::class)->ignore($line, auth()->user(), $data['alasan'] ?? null),
                    'Baris diabaikan',
                    'Alasannya tersimpan di baris itu.',
                );
            });
    }

    /**
     * Money in the books never saw: record the payment straight off the line.
     *
     * The amount and date come from the statement and cannot be typed — the
     * one thing the person supplies is *whose* money it was.
     */
    public function catatPembayaranAction(): Action
    {
        return Action::make('catatPembayaran')
            ->label('Catat pembayaran')
            ->color('primary')
            ->size('xs')
            ->modalHeading('Catat pembayaran dari mutasi')
            ->modalDescription(function (array $arguments) {
                $line = BankStatementLine::query()->find($arguments['line'] ?? 0);

                return $line === null ? '' : sprintf(
                    '%s — %s, %s. Nilai dan tanggal diambil dari mutasi; tinggal sebutkan uang siapa.',
                    $line->tanggal?->format('d/m/Y'),
                    $line->uraian,
                    Money::format((int) $line->amount_rupiah),
                );
            })
            ->schema([
                Select::make('invoice_id')
                    ->label('Faktur yang dibayar')
                    ->options(fn () => Invoice::query()
                        ->where('status', Invoice::STATUS_OPEN)
                        ->with('company')
                        ->orderByDesc('issued_on')
                        ->limit(200)
                        ->get()
                        ->mapWithKeys(fn (Invoice $i) => [
                            $i->id => "{$i->nomor} — {$i->company?->nama} — sisa "
                                .Money::format($i->amountOutstanding()),
                        ]))
                    ->searchable()
                    ->helperText('Kosongkan bila belum jelas fakturnya — pembayaran masuk sebagai belum terkait.'),

                Select::make('company_id')
                    ->label('Atau pelanggan yang membayar')
                    ->options(fn () => Company::query()->orderBy('nama')->pluck('nama', 'id'))
                    ->searchable(),
            ])
            ->action(function (array $data, array $arguments) {
                $line = BankStatementLine::query()->find($arguments['line'] ?? 0);

                if ($line === null) {
                    return;
                }

                $invoice = isset($data['invoice_id']) && $data['invoice_id']
                    ? Invoice::query()->find($data['invoice_id'])
                    : null;
                $company = isset($data['company_id']) && $data['company_id']
                    ? Company::query()->find($data['company_id'])
                    : null;

                $this->run(
                    fn () => app(StatementMatcher::class)->recordPayment(
                        $line,
                        auth()->user(),
                        $invoice,
                        $company,
                    ),
                    'Pembayaran dicatat dari mutasi',
                    'Jurnalnya diposting dan baris mutasinya tercocok.',
                );
            });
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
