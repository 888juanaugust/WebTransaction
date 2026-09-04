<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Banking\BankAccounts;
use App\Domain\Money;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\BankAccount;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
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
 * One transfer out; several of a supplier's bills discharged.
 *
 * The mirror of Terima pembayaran, and the case is stronger here: paying a
 * supplier once a month against everything they have sent is the ordinary
 * shape of a trade account, not the exception. Recording it bill by bill
 * produced four bank entries for one line on the statement, which is the one
 * thing the reconciliation desk cannot work with.
 *
 * The queue below is money that has left the account and discharges nothing —
 * the AP twin of an unmatched receipt, and the reason it is a screen rather
 * than a report: a payment nobody has applied is a supplier who will ring
 * about a bill we believe is outstanding.
 */
class BayarPemasok extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquare;

    protected static \UnitEnum|string|null $navigationGroup = 'Pembelian';

    protected static ?string $navigationLabel = 'Bayar pemasok';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'bayar-pemasok';

    protected string $view = 'filament.pages.bayar-pemasok';

    public function getTitle(): string
    {
        return 'Bayar pemasok';
    }

    /**
     * Finance and the Owner, which is exactly who may confirm money moving.
     * Deliberately not the wider purchasing permission: entering a bill and
     * paying one are different jobs.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $jumlah = SupplierPaymentEntry::query()->unmatched()->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int, SupplierPaymentEntry> */
    public function antrean(): Collection
    {
        return app(SupplierLedger::class)->unallocatedEntries();
    }

    public function sisa(SupplierPaymentEntry $entry): int
    {
        return app(SupplierLedger::class)->unallocated($entry);
    }

    public function rincian(SupplierPaymentEntry $entry): Collection
    {
        return $entry->allocations()->with(['bill', 'actor'])->orderBy('id')->get();
    }

    // --- paying ------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [$this->bayarAction()];
    }

    public function bayarAction(): Action
    {
        return Action::make('bayar')
            ->label('Catat pembayaran')
            ->icon(Heroicon::OutlinedBanknotes)
            ->modalHeading('Catat uang keluar')
            ->modalDescription('Satu pembayaran, satu baris rekening koran. Rinciannya boleh '
                .'menutup beberapa tagihan sekaligus.')
            ->modalWidth('3xl')
            ->schema([
                Select::make('supplier_id')
                    ->label('Pemasok')
                    ->options(fn () => Supplier::query()->orderBy('nama')->pluck('nama', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, $set, $get) => $set(
                        'alokasi',
                        $this->usulan((int) $state, (int) ($get('jumlah_rupiah') ?? 0)),
                    )),

                TextInput::make('jumlah_rupiah')
                    ->label('Jumlah dibayar (Rp)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $get) => $set(
                        'alokasi',
                        $this->usulan((int) ($get('supplier_id') ?? 0), (int) $state),
                    )),

                TextInput::make('referensi')->label('Referensi transfer')->maxLength(120),

                Select::make('bank_account_id')
                    ->label('Keluar dari rekening')
                    ->options(fn () => BankAccount::query()->aktif()
                        ->get()->mapWithKeys(fn (BankAccount $r) => [$r->id => $r->label()]))
                    ->default(fn () => app(BankAccounts::class)->default()->id)
                    ->required()
                    ->visible(fn () => BankAccount::query()->aktif()->count() > 1),

                DateTimePicker::make('paid_at')
                    ->label('Tanggal bayar')
                    ->default(now())
                    ->helperText('Tanggal uangnya keluar, bukan tanggal diketik.'),

                Repeater::make('alokasi')
                    ->label('Untuk tagihan mana')
                    ->helperText('Terisi otomatis dari tagihan terlama. Ubah bila pemasok '
                        .'menyebut faktur tertentu; sisanya boleh dicocokkan nanti.')
                    ->addActionLabel('Tambah tagihan')
                    ->columns(2)
                    ->default([])
                    ->schema([
                        Select::make('supplier_bill_id')
                            ->label('Tagihan')
                            ->options(fn ($get) => $this->tagihanPilihan(
                                (int) ($get('../../supplier_id') ?? 0)
                            ))
                            ->required(),

                        TextInput::make('jumlah')
                            ->label('Dipakai (Rp)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);

                    app(SupplierLedger::class)->recordPayment(
                        supplier: $supplier,
                        amountRupiah: (int) $data['jumlah_rupiah'],
                        actor: auth()->user(),
                        referensi: $data['referensi'] ?? null,
                        catatan: $data['catatan'] ?? null,
                        paidAt: ($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at']) : null,
                        rekening: isset($data['bank_account_id'])
                            ? BankAccount::query()->find($data['bank_account_id'])
                            : null,
                        spread: $this->bacaAlokasi($data['alokasi'] ?? []),
                    );

                    Notification::make()->title('Pembayaran dicatat')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Tidak bisa dicatat')
                        ->body($e->getMessage())->danger()->send();
                }
            });
    }

    // --- clearing the queue -----------------------------------------------

    public function cocokkanAction(): Action
    {
        return Action::make('cocokkan')
            ->label('Cocokkan')
            ->icon(Heroicon::OutlinedLink)
            ->size('xs')
            ->modalHeading('Cocokkan ke tagihan')
            ->schema(fn (array $arguments) => [
                Select::make('supplier_bill_id')
                    ->label('Tagihan')
                    ->options($this->tagihanPilihan(
                        (int) (SupplierPaymentEntry::query()
                            ->find($arguments['entry'] ?? 0)?->supplier_id ?? 0)
                    ))
                    ->required()
                    ->live()
                    // Set on change; a form default is evaluated at mount,
                    // before any tagihan is chosen.
                    ->afterStateUpdated(fn ($state, $set) => $set('jumlah', $this->usulanSatu(
                        (int) ($arguments['entry'] ?? 0),
                        (int) $state,
                    ))),

                TextInput::make('jumlah')
                    ->label('Dipakai (Rp)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->helperText('Terisi dengan yang lebih dulu habis — uangnya atau tagihannya.'),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    app(SupplierLedger::class)->allocate(
                        entry: SupplierPaymentEntry::query()->findOrFail((int) $arguments['entry']),
                        bill: SupplierBill::query()->findOrFail((int) $data['supplier_bill_id']),
                        amountRupiah: (int) $data['jumlah'],
                        actor: auth()->user(),
                    );

                    Notification::make()->title('Dicocokkan')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Tidak bisa dicocokkan')
                        ->body($e->getMessage())->danger()->send();
                }
            });
    }

    public function batalkanAction(): Action
    {
        return Action::make('batalkan')
            ->label('Batalkan')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->size('xs')
            ->modalHeading('Batalkan pencocokan')
            ->modalDescription('Uangnya tetap tercatat keluar. Yang dibatalkan hanya keterangan '
                .'tagihan mana yang ditutupnya.')
            ->schema([
                Textarea::make('alasan')->label('Alasan')->required()->rows(2),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    app(SupplierLedger::class)->unallocate(
                        alokasi: SupplierPaymentAllocation::query()
                            ->findOrFail((int) $arguments['alokasi']),
                        actor: auth()->user(),
                        alasan: $data['alasan'],
                    );

                    Notification::make()->title('Pencocokan dibatalkan')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Tidak bisa dibatalkan')
                        ->body($e->getMessage())->danger()->send();
                }
            });
    }

    // --- the suggestion ---------------------------------------------------

    /** @return list<array{supplier_bill_id: int, jumlah: int}> */
    private function usulan(int $supplierId, int $jumlah): array
    {
        $supplier = $supplierId > 0 ? Supplier::query()->find($supplierId) : null;

        if ($supplier === null || $jumlah <= 0) {
            return [];
        }

        return array_map(
            fn (array $baris) => [
                'supplier_bill_id' => $baris['bill']->id,
                'jumlah' => $baris['amount'],
            ],
            app(SupplierLedger::class)->suggestSpread($supplier, $jumlah),
        );
    }

    /** Whichever runs out first: what is left of the money, or of the bill. */
    private function usulanSatu(int $entryId, int $billId): ?int
    {
        $entry = SupplierPaymentEntry::query()->find($entryId);
        $bill = SupplierBill::query()->find($billId);

        if ($entry === null || $bill === null) {
            return null;
        }

        return max(0, min(
            app(SupplierLedger::class)->unallocated($entry),
            $bill->amountOutstanding(),
        ));
    }

    /** @return array<int, string> */
    private function tagihanPilihan(int $supplierId): array
    {
        $supplier = $supplierId > 0 ? Supplier::query()->find($supplierId) : null;

        if ($supplier === null) {
            return [];
        }

        return app(SupplierLedger::class)->openBills($supplier)
            ->mapWithKeys(fn (SupplierBill $b) => [
                $b->id => "{$b->nomor} — kurang ".Money::format($b->amountOutstanding())
                    .' — jatuh tempo '.($b->due_date?->format('d/m/Y') ?? '—'),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{0: SupplierBill, 1: int}>
     */
    private function bacaAlokasi(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $bill = SupplierBill::query()->find((int) ($row['supplier_bill_id'] ?? 0));

            if ($bill === null || (int) ($row['jumlah'] ?? 0) <= 0) {
                continue;
            }

            $out[] = [$bill, (int) $row['jumlah']];
        }

        return $out;
    }
}
