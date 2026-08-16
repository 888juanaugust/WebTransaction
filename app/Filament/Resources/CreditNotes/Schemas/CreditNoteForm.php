<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Domain\Billing\CreditableLine;
use App\Domain\Billing\CreditNoteIssuer;
use App\Domain\Billing\CreditNoteType;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Money;
use App\Models\Invoice;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Raising a credit note.
 *
 * The form's job is to stop somebody entering a return that cannot be true.
 * It offers only lines that actually shipped, and only up to the quantity
 * still outstanding — the same numbers CreditNotePoster will check, read from
 * the same CreditNoteIssuer, so what is offered and what is accepted cannot
 * drift apart. A form that lets you type a quantity the poster then refuses
 * teaches people to distrust the control.
 *
 * No prices are entered for a retur. The value comes from the invoice the
 * goods were billed on, apportioned by quantity, and there is deliberately no
 * field for overriding it: a return credited at a price the customer never
 * paid is the whole thing this document has to avoid.
 */
class CreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Nota kredit')
                    ->columns(3)
                    ->schema([
                        Select::make('invoice_id')
                            ->label('Faktur yang dikreditkan')
                            ->options(fn () => Invoice::query()
                                ->where('status', '!=', Invoice::STATUS_VOID)
                                ->with('company')
                                ->orderByDesc('issued_on')
                                ->limit(200)
                                ->get()
                                ->mapWithKeys(fn (Invoice $i) => [
                                    $i->id => "{$i->nomor} — {$i->company?->nama}",
                                ]))
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(function ($state, $set) {
                                $invoice = $state ? Invoice::query()->find($state) : null;
                                $set('company_id', $invoice?->company_id);
                                $set('lines', []);
                            })
                            ->helperText('Nilai kredit dihitung dari harga pada faktur ini.'),

                        Select::make('jenis')
                            ->label('Jenis')
                            ->options(fn () => collect(CreditNoteType::cases())
                                ->mapWithKeys(fn (CreditNoteType $t) => [$t->value => $t->label()]))
                            ->default(CreditNoteType::ReturBarang->value)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->helperText(fn (Get $get) => $get('jenis')
                                ? CreditNoteType::from($get('jenis'))->description()
                                : null),

                        DatePicker::make('tanggal')
                            ->label('Tanggal')
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),

                        Select::make('warehouse_id')
                            ->label('Gudang penerima')
                            ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                            ->required(fn (Get $get) => $get('jenis') === CreditNoteType::ReturBarang->value)
                            ->visible(fn (Get $get) => $get('jenis') === CreditNoteType::ReturBarang->value)
                            ->helperText('Ke mana barang yang kembali dimasukkan.'),

                        TextInput::make('nomor_nota_retur')
                            ->label('Nomor nota retur pembeli')
                            ->maxLength(60)
                            ->visible(fn (Get $get) => $get('jenis') === CreditNoteType::ReturBarang->value)
                            ->helperText('Kalau pembeli sudah menerbitkannya. Konfirmasikan perlakuan pajaknya ke akuntan.'),

                        Textarea::make('alasan')
                            ->label('Alasan')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('mis. dua dus penyok saat pengiriman, disepakati diretur')
                            ->helperText('Wajib. Ini uang yang kembali ke pelanggan, dan "kenapa" adalah pertanyaan pertama yang akan ditanyakan nanti.'),

                        Hidden::make('company_id'),
                        Hidden::make('nomor')
                            ->default(fn () => app(DocumentNumberGenerator::class)->nextCreditNoteNumber()),
                        Hidden::make('created_by')->default(fn () => auth()->id()),
                    ]),

                Section::make('Baris')
                    ->description('Hanya baris yang benar-benar sudah dikirim yang bisa diretur.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->addActionLabel('Tambah baris')
                            ->defaultItems(0)
                            ->columns(4)
                            ->schema([
                                Select::make('order_line_id')
                                    ->label('Barang')
                                    ->options(fn (Get $get) => static::lineOptions($get('../../invoice_id')))
                                    ->required(fn (Get $get) => $get('../../jenis') === CreditNoteType::ReturBarang->value)
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set, Get $get) {
                                        $line = static::creditableFor($get('../../invoice_id'), $state);
                                        $set('sku', $line?->sku);
                                    })
                                    ->columnSpan(2)
                                    ->placeholder('Penyelesaian atas faktur (tanpa baris)'),

                                TextInput::make('qty_base')
                                    ->label('Jumlah retur')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(0)
                                    ->visible(fn (Get $get) => $get('../../jenis') === CreditNoteType::ReturBarang->value)
                                    ->required(fn (Get $get) => $get('../../jenis') === CreditNoteType::ReturBarang->value)
                                    ->maxValue(fn (Get $get) => static::creditableFor(
                                        $get('../../invoice_id'), $get('order_line_id')
                                    )?->remainingQty() ?: 1)
                                    ->helperText(function (Get $get) {
                                        $line = static::creditableFor($get('../../invoice_id'), $get('order_line_id'));

                                        return $line === null
                                            ? null
                                            : sprintf(
                                                'Dikirim %s, sisa yang bisa diretur %s.',
                                                number_format($line->shippedQty, 0, ',', '.'),
                                                number_format($line->remainingQty(), 0, ',', '.'),
                                            );
                                    }),

                                TextInput::make('line_total_rupiah')
                                    ->label('Nilai kredit')
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get) => $get('../../jenis') === CreditNoteType::Potongan->value)
                                    ->required(fn (Get $get) => $get('../../jenis') === CreditNoteType::Potongan->value)
                                    ->prefix('Rp')
                                    ->helperText(function (Get $get) {
                                        $line = static::creditableFor($get('../../invoice_id'), $get('order_line_id'));

                                        return $line === null
                                            ? 'Belum termasuk PPN.'
                                            : 'Belum termasuk PPN. Sisa nilai baris '
                                                .Money::format($line->remainingValueRupiah()).'.';
                                    }),

                                TextInput::make('sku')
                                    ->label('Kode')
                                    ->required()
                                    ->maxLength(50)
                                    ->columnSpan(1),
                            ]),
                    ]),
            ]);
    }

    /**
     * Only what shipped, and only what has not already been credited.
     *
     * @return array<int, string>
     */
    private static function lineOptions(mixed $invoiceId): array
    {
        $invoice = $invoiceId ? Invoice::query()->find($invoiceId) : null;

        if ($invoice === null) {
            return [];
        }

        $options = [];

        foreach (app(CreditNoteIssuer::class)->creditable($invoice) as $line) {
            if ($line->isFullyCredited()) {
                continue;
            }

            $options[$line->orderLine->id] = sprintf(
                '%s — sisa %s dari %s dikirim',
                $line->sku,
                number_format($line->remainingQty(), 0, ',', '.'),
                number_format($line->shippedQty, 0, ',', '.'),
            );
        }

        return $options;
    }

    private static function creditableFor(mixed $invoiceId, mixed $orderLineId): ?CreditableLine
    {
        if (! $invoiceId || ! $orderLineId) {
            return null;
        }

        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null) {
            return null;
        }

        foreach (app(CreditNoteIssuer::class)->creditable($invoice) as $line) {
            if ($line->orderLine->id === (int) $orderLineId) {
                return $line;
            }
        }

        return null;
    }
}
