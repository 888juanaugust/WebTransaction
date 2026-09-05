<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Money;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\SupplierBill;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The journal register — every entry, newest first, with its lines underneath.
 *
 * This is the screen that makes the two statements trustworthy. A figure on a
 * balance sheet is only as good as the ability to ask where it came from, and
 * the answer is always the same shape: an entry, its lines, and the document
 * behind it.
 *
 * Read-only. There is no edit action and no delete action, because a posted
 * entry is evidence. Corrections are reversals, and those are made from the
 * document that was wrong.
 */
class Jurnal extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::BUKU_BESAR;

    protected static ?string $navigationLabel = 'Jurnal';

    protected static ?int $navigationSort = 74;

    protected static ?string $slug = 'akuntansi/jurnal';

    protected string $view = 'filament.pages.akuntansi.jurnal';

    public function getTitle(): string
    {
        return 'Jurnal umum';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeBooks() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                JournalEntry::query()
                    ->with(['lines.account', 'postedBy'])
                    ->orderByDesc('tanggal')
                    ->orderByDesc('id')
            )
            ->columns([
                TextColumn::make('nomor')
                    ->label('Nomor')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('tanggal')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->searchable()
                    ->wrap()
                    ->description(fn (JournalEntry $entry) => $entry->lines
                        ->map(fn ($line) => $line->account->kode.' '
                            .($line->debit_rupiah > 0 ? 'D' : 'K').' '
                            .number_format($line->debit_rupiah ?: $line->kredit_rupiah, 0, ',', '.'))
                        ->implode('  ·  ')),

                TextColumn::make('jenis')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::jenisLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        JournalEntry::JENIS_PEMBALIKAN => 'danger',
                        JournalEntry::JENIS_MANUAL => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('total_debit_rupiah')
                    ->label('Nilai')
                    // Whole rupiah. ->money('IDR') renders "Rp 4.415.025,00",
                    // and a currency with no subunit does not have cents.
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('postedBy.name')
                    ->label('Diposting oleh')
                    ->placeholder('Otomatis')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('jenis')
                    ->label('Jenis')
                    ->options(collect(static::jenisOptions())->all()),

                Filter::make('periode')
                    ->schema([
                        DatePicker::make('dari')->label('Dari')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('sampai')->label('Sampai')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $d) => $q->whereDate('tanggal', '>=', $d))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $d) => $q->whereDate('tanggal', '<=', $d))),
            ])
            ->recordActions([
                Action::make('sumber')
                    ->label('Dokumen')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (JournalEntry $entry) => static::sourceUrl($entry))
                    ->visible(fn (JournalEntry $entry) => static::sourceUrl($entry) !== null)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Belum ada jurnal')
            ->emptyStateDescription('Jurnal terbentuk sendiri dari faktur, penerimaan barang, tagihan dan pembayaran.')
            ->defaultPaginationPageOption(25);
    }

    /** @return array<string, string> */
    public static function jenisOptions(): array
    {
        return [
            JournalEntry::JENIS_PENJUALAN => 'Penjualan',
            JournalEntry::JENIS_HPP => 'Harga pokok',
            JournalEntry::JENIS_PENERIMAAN_BARANG => 'Penerimaan barang',
            JournalEntry::JENIS_TAGIHAN_PEMASOK => 'Tagihan pemasok',
            JournalEntry::JENIS_PEMBAYARAN_PELANGGAN => 'Pembayaran pelanggan',
            JournalEntry::JENIS_PEMBAYARAN_PEMASOK => 'Pembayaran pemasok',
            JournalEntry::JENIS_UANG_MUKA => 'Uang muka pelanggan',
            JournalEntry::JENIS_UANG_MUKA_DIPAKAI => 'Uang muka dipakai',
            JournalEntry::JENIS_UANG_MUKA_KEMBALI => 'Uang muka dikembalikan',
            JournalEntry::JENIS_NOTA_KREDIT => 'Nota kredit',
            JournalEntry::JENIS_HPP_RETUR => 'Harga pokok retur',
            JournalEntry::JENIS_RETUR_PEMBELIAN => 'Retur pembelian',
            JournalEntry::JENIS_NOTA_KREDIT_PEMASOK => 'Nota kredit pemasok',
            JournalEntry::JENIS_BEBAN => 'Beban',
            JournalEntry::JENIS_AKTIVA_TETAP => 'Aktiva tetap',
            JournalEntry::JENIS_PENYUSUTAN => 'Penyusutan',
            JournalEntry::JENIS_PELEPASAN_ASET => 'Pelepasan aktiva',
            JournalEntry::JENIS_GIRO => 'Bilyet giro',
            JournalEntry::JENIS_GIRO_SELESAI => 'Giro selesai',
            JournalEntry::JENIS_REKONSILIASI_BANK => 'Rekonsiliasi bank',
            JournalEntry::JENIS_SELISIH_OPNAME => 'Selisih stok opname',
            JournalEntry::JENIS_BIAYA_PEROLEHAN => 'Biaya perolehan',
            JournalEntry::JENIS_TUTUP_BUKU => 'Tutup buku',
            JournalEntry::JENIS_MANUAL => 'Jurnal manual',
            JournalEntry::JENIS_PEMBALIKAN => 'Pembalikan',
        ];
    }

    public static function jenisLabel(string $jenis): string
    {
        return static::jenisOptions()[$jenis] ?? $jenis;
    }

    /**
     * Where to go to see what caused this entry.
     *
     * Almost everything links to its register, searched for the document's own
     * number, rather than straight to the record. That looks like the lazy
     * option and is in fact the only one that works: an entry exists *because*
     * a document was posted, and every one of these resources refuses to edit
     * a posted document. Linking to `edit` produced a row of buttons that all
     * returned 403 — a link that looks right and is not is worse than no link.
     *
     * Orders are the exception, because they have a real view screen that
     * stays open at every status, and it is the better destination anyway: the
     * transition log is on it.
     *
     * A payment entry has no screen of its own — it is a row on the invoice —
     * so it gets no link at all.
     */
    public static function sourceUrl(JournalEntry $entry): ?string
    {
        if ($entry->source_id === null) {
            return null;
        }

        if ($entry->source_type === Order::class) {
            return rescue(
                fn () => OrderResource::getUrl('view', ['record' => $entry->source_id]),
                fn () => null,
                report: false,
            );
        }

        $resource = match ($entry->source_type) {
            Invoice::class => InvoiceResource::class,
            GoodsReceipt::class => GoodsReceiptResource::class,
            SupplierBill::class => SupplierBillResource::class,
            default => null,
        };

        if ($resource === null) {
            return null;
        }

        $nomor = rescue(
            fn () => $entry->source_type::query()->whereKey($entry->source_id)->value('nomor'),
            fn () => null,
            report: false,
        );

        if ($nomor === null) {
            return null;
        }

        return rescue(
            fn () => $resource::getUrl('index', ['tableSearch' => $nomor]),
            fn () => null,
            report: false,
        );
    }
}
