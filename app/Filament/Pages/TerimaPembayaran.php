<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Banking\BankAccounts;
use App\Domain\Money;
use App\Domain\Payments\PaymentLedger;
use App\Filament\Navigation\SidebarGroups;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentEntry;
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
 * One transfer arrives; several fakturs get settled.
 *
 * A customer on 30-day terms does not pay invoice by invoice. At month end
 * they send one figure covering four fakturs and part of a fifth, and until
 * this screen existed finance had to either split that transfer into four
 * entries — which then no longer tie to the single line the reconciliation
 * desk ticks against — or point the whole amount at one faktur and leave the
 * rest of the customer's money on no invoice at all.
 *
 * The screen is a queue, not a form: what is on it is money that has arrived
 * and is not fully accounted for. Recording a receipt is the header action;
 * clearing the queue is the work.
 *
 * The oldest-first spread is offered as an editable default, never applied
 * silently. Oldest-first is what both sides assume when a customer says
 * "this is for my outstanding" — but which faktur a payment settles can be
 * the customer's own instruction, and the person on the phone with them has
 * to be able to follow it.
 */
class TerimaPembayaran extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::KEUANGAN;

    protected static ?string $navigationLabel = 'Terima pembayaran';

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'terima-pembayaran';

    protected string $view = 'filament.pages.terima-pembayaran';

    public function getTitle(): string
    {
        return 'Terima pembayaran';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    /** Money in that nobody has finished accounting for. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $jumlah = PaymentEntry::query()->unmatched()->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int, PaymentEntry> */
    public function antrean(): Collection
    {
        return app(PaymentLedger::class)->unallocatedEntries();
    }

    public function sisa(PaymentEntry $entry): int
    {
        return app(PaymentLedger::class)->unallocated($entry);
    }

    /** Applications standing on this entry, the reversed ones included. */
    public function rincian(PaymentEntry $entry): Collection
    {
        return $entry->allocations()->with(['invoice', 'actor'])->orderBy('id')->get();
    }

    // --- recording a receipt ----------------------------------------------

    protected function getHeaderActions(): array
    {
        return [$this->terimaAction()];
    }

    public function terimaAction(): Action
    {
        return Action::make('terima')
            ->label('Catat penerimaan')
            ->icon(Heroicon::OutlinedBanknotes)
            ->modalHeading('Catat uang masuk')
            ->modalDescription('Satu penerimaan, satu baris rekening koran. Rinciannya boleh '
                .'menyentuh beberapa faktur — atau tidak satu pun, kalau belum jelas untuk apa.')
            ->modalWidth('3xl')
            ->schema([
                Select::make('company_id')
                    ->label('Pelanggan')
                    ->options(fn () => Company::query()->orderBy('nama')->pluck('nama', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    // Changing who paid invalidates every line below it.
                    ->afterStateUpdated(fn ($state, $set, $get) => $set(
                        'alokasi',
                        $this->usulan((int) $state, (int) ($get('jumlah_rupiah') ?? 0)),
                    )),

                TextInput::make('jumlah_rupiah')
                    ->label('Jumlah diterima (Rp)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $get) => $set(
                        'alokasi',
                        $this->usulan((int) ($get('company_id') ?? 0), (int) $state),
                    )),

                Select::make('bank_account_id')
                    ->label('Masuk ke rekening')
                    ->options(fn () => BankAccount::query()->aktif()
                        ->get()->mapWithKeys(fn (BankAccount $r) => [$r->id => $r->label()]))
                    ->default(fn () => app(BankAccounts::class)->default()->id)
                    ->required()
                    // With one rekening there is nothing to choose.
                    ->visible(fn () => BankAccount::query()->aktif()->count() > 1),

                DateTimePicker::make('paid_at')
                    ->label('Tanggal terima')
                    ->default(now())
                    ->helperText('Tanggal uangnya masuk, bukan tanggal diketik.'),

                Repeater::make('alokasi')
                    ->label('Untuk faktur mana')
                    ->helperText('Terisi otomatis dari faktur terlama. Ubah bila pelanggan '
                        .'menyebut faktur tertentu; sisanya boleh dibiarkan dan dicocokkan nanti.')
                    ->addActionLabel('Tambah faktur')
                    ->columns(2)
                    ->default([])
                    ->schema([
                        Select::make('invoice_id')
                            ->label('Faktur')
                            ->options(fn ($get) => $this->fakturPilihan(
                                (int) ($get('../../company_id') ?? 0)
                            ))
                            ->required(),

                        TextInput::make('jumlah')
                            ->label('Dipakai (Rp)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),

                Textarea::make('catatan')->label('Catatan')->rows(2)
                    ->placeholder('Nomor referensi transfer, nama pengirim, apa pun yang '
                        .'membantu mencocokkan ke rekening koran.'),
            ])
            ->action(function (array $data) {
                try {
                    $company = Company::query()->findOrFail((int) $data['company_id']);

                    app(PaymentLedger::class)->recordManualPayment(
                        company: $company,
                        amountRupiah: (int) $data['jumlah_rupiah'],
                        actor: auth()->user(),
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
            ->modalHeading('Cocokkan ke faktur')
            ->schema(fn (array $arguments) => [
                Select::make('invoice_id')
                    ->label('Faktur')
                    ->options($this->fakturPilihan(
                        (int) (PaymentEntry::query()->find($arguments['entry'] ?? 0)?->company_id ?? 0)
                    ))
                    ->required()
                    ->live()
                    /*
                     * Fill the amount when the faktur is picked, not as a
                     * form default: a default is evaluated once at mount,
                     * when no faktur has been chosen and there is nothing to
                     * compute from, so the field would simply stay empty.
                     */
                    ->afterStateUpdated(fn ($state, $set) => $set('jumlah', $this->usulanSatu(
                        (int) ($arguments['entry'] ?? 0),
                        (int) $state,
                    ))),

                TextInput::make('jumlah')
                    ->label('Dipakai (Rp)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->helperText('Terisi dengan yang lebih dulu habis — uangnya atau tagihannya. '
                        .'Boleh dikurangi; sisanya tetap menunggu di daftar ini.'),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    app(PaymentLedger::class)->allocate(
                        entry: PaymentEntry::query()->findOrFail((int) $arguments['entry']),
                        invoice: Invoice::query()->withoutGlobalScope('region')
                            ->findOrFail((int) $data['invoice_id']),
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
            ->modalDescription('Uangnya tetap tercatat dan tetap milik pelanggan. Yang dibatalkan '
                .'hanya keterangan faktur mana yang dilunasinya.')
            ->schema([
                Textarea::make('alasan')->label('Alasan')->required()->rows(2),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    app(PaymentLedger::class)->unallocate(
                        alokasi: PaymentAllocation::query()->findOrFail((int) $arguments['alokasi']),
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

    /**
     * The oldest-first spread, in the shape the repeater wants.
     *
     * @return list<array{invoice_id: int, jumlah: int}>
     */
    private function usulan(int $companyId, int $jumlah): array
    {
        $company = $companyId > 0 ? Company::query()->find($companyId) : null;

        if ($company === null || $jumlah <= 0) {
            return [];
        }

        return array_map(
            fn (array $baris) => [
                'invoice_id' => $baris['invoice']->id,
                'jumlah' => $baris['amount'],
            ],
            app(PaymentLedger::class)->suggestSpread($company, $jumlah),
        );
    }

    /** Whichever runs out first: what is left of the money, or of the bill. */
    private function usulanSatu(int $entryId, int $invoiceId): ?int
    {
        $entry = PaymentEntry::query()->find($entryId);
        $invoice = Invoice::query()->withoutGlobalScope('region')->find($invoiceId);

        if ($entry === null || $invoice === null) {
            return null;
        }

        return max(0, min(
            app(PaymentLedger::class)->unallocated($entry),
            $invoice->amountOutstanding(),
        ));
    }

    /** @return array<int, string> */
    private function fakturPilihan(int $companyId): array
    {
        $company = $companyId > 0 ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return [];
        }

        return app(PaymentLedger::class)->openInvoices($company)
            ->mapWithKeys(fn (Invoice $i) => [
                $i->id => "{$i->nomor} — kurang ".Money::format($i->amountOutstanding())
                    .' — jatuh tempo '.($i->due_date?->format('d/m/Y') ?? '—'),
            ])
            ->all();
    }

    /**
     * Repeater rows into what the ledger takes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{0: Invoice, 1: int}>
     */
    private function bacaAlokasi(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $invoice = Invoice::query()->withoutGlobalScope('region')
                ->find((int) ($row['invoice_id'] ?? 0));

            if ($invoice === null || (int) ($row['jumlah'] ?? 0) <= 0) {
                continue;
            }

            $out[] = [$invoice, (int) $row['jumlah']];
        }

        return $out;
    }
}
