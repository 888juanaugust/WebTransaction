<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns\Schemas;

use App\Domain\Money;
use App\Domain\Purchasing\PurchaseReturnIssuer;
use App\Domain\Purchasing\ReturnableLine;
use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Adjusting a drawn-up return before it is posted.
 *
 * The draft arrives with every returnable line on it and the full quantity
 * against each, because that is what usually happens — a delivery is wrong and
 * the lot goes back on the same truck. So this form's whole job is subtraction:
 * change a quantity, or take a line off.
 *
 * **There is no money on it, anywhere.** What the return is worth is decided at
 * posting, from the receipt's own cost and the supplier's own bill. A field for
 * it here would be a second place deciding what goods are worth, and the one
 * place people would type whatever made the variance disappear.
 *
 * The supplier, the receipt and the warehouse are fixed once drawn. Changing
 * any of them means a different document, and drawing a new one takes two
 * clicks.
 */
class PurchaseReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Retur pembelian')
                    ->columns(3)
                    ->schema([
                        TextInput::make('nomor')
                            ->label('Nomor')
                            ->disabled(),

                        /*
                         * Formatted off the record rather than the form state.
                         * Livewire serialises a Carbon into the wire payload as
                         * a UTC instant, so a date that is midnight in Jakarta
                         * comes back as 17:00 the previous day.
                         */
                        TextInput::make('pemasok')
                            ->label('Pemasok')
                            ->disabled()
                            ->formatStateUsing(fn (?PurchaseReturn $record) => $record?->supplier?->nama),

                        TextInput::make('penerimaan')
                            ->label('Dari penerimaan')
                            ->disabled()
                            ->formatStateUsing(fn (?PurchaseReturn $record) => $record?->goodsReceipt?->nomor),

                        DatePicker::make('tanggal')
                            ->label('Tanggal')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),

                        TextInput::make('nomor_nota_kredit_supplier')
                            ->label('Nota kredit dari pemasok')
                            ->maxLength(60)
                            ->columnSpan(2)
                            ->helperText(
                                'Diisi kalau pemasok sudah mengirim nota kreditnya. '
                                .'Yang belum terisi muncul sebagai lencana di menu.'
                            ),

                        Textarea::make('alasan')
                            ->label('Alasan')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('mis. 3 dus salah tipe, sudah dikonfirmasi ke pemasok lewat WA')
                            ->helperText(
                                'Wajib. Ini barang keluar dari gudang dan utang yang berkurang, '
                                .'dan "kenapa" adalah pertanyaan pertama yang akan ditanyakan nanti.'
                            ),
                    ]),

                Section::make('Barang yang dikembalikan')
                    ->description(
                        'Hapus baris yang tidak jadi diretur, atau kurangi jumlahnya. '
                        .'Nilainya dihitung saat diposting, dari harga di penerimaan dan tagihan pemasok.'
                    )
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->addable(false)
                            ->reorderable(false)
                            ->deleteAction(fn ($action) => $action->label('Hapus baris'))
                            ->columns(4)
                            ->schema([
                                TextInput::make('sku')
                                    ->label('Kode')
                                    ->disabled(),

                                TextInput::make('deskripsi')
                                    ->label('Barang')
                                    ->disabled()
                                    ->columnSpan(2),

                                /*
                                 * `$record` inside a `->relationship()`
                                 * repeater is the *line*, not the return —
                                 * Filament scopes injection to the item being
                                 * rendered. Typing it as the parent threw a
                                 * TypeError that took the whole edit page
                                 * down, and no test saw it because the screen
                                 * tests only opened the list and the detail.
                                 */
                                TextInput::make('qty_base')
                                    ->label('Jumlah retur')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->maxValue(fn (?PurchaseReturnLine $record) => static::returnableFor($record)
                                        ?->remainingQty() ?: 1)
                                    ->helperText(fn (?PurchaseReturnLine $record) => static::helper(
                                        static::returnableFor($record)
                                    )),
                            ]),
                    ]),
            ]);
    }

    /**
     * What the line beside the box is: how much arrived, how much of it the
     * supplier has billed, and what it cost.
     *
     * The billed figure is on the form deliberately. It is the one thing that
     * changes what posting this document does to the books, and somebody
     * deciding how much to send back should be able to see whether they are
     * reducing a debt or unwinding an accrual.
     */
    private static function helper(?ReturnableLine $line): ?string
    {
        if ($line === null) {
            return null;
        }

        $received = number_format($line->receivedQty, 0, ',', '.');
        $harga = Money::format($line->unitCostRupiah());

        // "Diterima 120, sisa 120" reads as a system that cannot subtract.
        // The remaining figure only earns its place once part has gone back.
        $parts = [$line->remainingQty() === $line->receivedQty
            ? sprintf('Diterima %s @ %s.', $received, $harga)
            : sprintf(
                'Diterima %s @ %s, sisa yang bisa diretur %s.',
                $received,
                $harga,
                number_format($line->remainingQty(), 0, ',', '.'),
            )];

        $billed = $line->remainingBilledQty();

        if ($billed === 0) {
            $parts[] = 'Belum ditagih pemasok: retur ini membatalkan akrual dan tidak menyentuh PPN.';
        } elseif ($billed >= $line->remainingQty()) {
            // No remainder to promise anything about. Saying "sisanya
            // membatalkan akrual" when there is no rest describes a second
            // half of the posting that will not happen.
            $parts[] = 'Sudah ditagih pemasok: mengurangi utang berikut PPN masukannya.';
        } else {
            $parts[] = sprintf(
                'Ditagih %s unit: sebanyak itu mengurangi utang, sisanya membatalkan akrual.',
                number_format($billed, 0, ',', '.'),
            );
        }

        return implode(' ', $parts);
    }

    /**
     * The receipt line behind one draft line, read off the line itself.
     *
     * Both the receipt and the receipt line come from the record rather than
     * from form state. Lines here are never added by hand — the repeater is
     * `addable(false)` and every line was drawn from a receipt — so the
     * relationship is always there, and reading it is one less thing that can
     * disagree with what the poster will check.
     */
    private static function returnableFor(?PurchaseReturnLine $record): ?ReturnableLine
    {
        $receipt = $record?->purchaseReturn?->goodsReceipt;

        if ($receipt === null) {
            return null;
        }

        foreach (app(PurchaseReturnIssuer::class)->returnable($receipt) as $line) {
            if ($line->receiptLine->id === (int) $record->goods_receipt_line_id) {
                return $line;
            }
        }

        return null;
    }

    /** Posted receipts with something left on them, for the draw-up action. */
    public static function receiptOptions(): array
    {
        $issuer = app(PurchaseReturnIssuer::class);

        return GoodsReceipt::query()
            ->with(['supplier', 'lines'])
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->orderByDesc('tanggal_terima')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->filter(fn (GoodsReceipt $receipt) => $issuer->remainingQty($receipt) > 0)
            ->mapWithKeys(fn (GoodsReceipt $receipt) => [
                $receipt->id => sprintf(
                    '%s · %s · %s',
                    $receipt->nomor,
                    $receipt->supplier?->nama ?? '—',
                    $receipt->tanggal_terima?->format('d/m/Y') ?? '—',
                ),
            ])
            ->all();
    }
}
