<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders\Schemas;

use App\Domain\Money;
use App\Domain\Orders\OrderFamily;
use App\Domain\Orders\OrderStatus;
use App\Filament\Portal\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * One order, as the buyer sees it.
 *
 * Every number here comes from the line snapshots taken at `confirmed`. This
 * screen never joins to the live price list — a customer looking at a
 * six-month-old order must see what they were charged, not what the SKU costs
 * today.
 */
class OrderDetail
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Ringkasan')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('nomor')->label('Nomor pesanan'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                            ->color(fn (OrderStatus $state) => OrdersTable::statusColor($state)),

                        TextEntry::make('created_at')->label('Tanggal pesan')->date('d/m/Y'),

                        TextEntry::make('po_pelanggan')->label('PO Anda')->placeholder('—'),
                    ]),

                Section::make('Barang')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->columns(5)
                            ->schema([
                                TextEntry::make('sku')->label('Kode'),

                                TextEntry::make('description_snapshot')
                                    ->label('Barang')
                                    ->columnSpan(2)
                                    // Snapshot first; the product record is only
                                    // a fallback for orders placed before the
                                    // snapshot columns existed.
                                    ->state(fn ($record) => trim(
                                        ($record->merk_snapshot ?? $record->product?->merk ?? '')
                                        .' '.($record->description_snapshot ?? $record->product?->description ?? '')
                                    ) ?: $record->sku),

                                TextEntry::make('ordered_qty')
                                    ->label('Jumlah')
                                    ->state(fn ($record) => $record->ordered_qty.' '
                                        .$record->ordered_unit->label()
                                        .($record->ordered_unit->isBaseUnit()
                                            ? ''
                                            : " ({$record->qty_base} {$record->satuan_dasar_snapshot})")),

                                TextEntry::make('line_total_rupiah')
                                    ->label('Subtotal')
                                    ->state(fn ($record) => $record->isPriced()
                                        ? Money::format((int) $record->line_total_rupiah)
                                        : '—'),
                            ]),
                    ]),

                Section::make('Nilai pesanan')
                    ->columns(4)
                    // Hidden rather than zeroed before confirmation: an order
                    // that has not been priced has no total, and showing Rp 0
                    // invites an argument later.
                    ->visible(fn (Order $record) => $record->confirmed_at !== null)
                    ->schema([
                        TextEntry::make('subtotal_rupiah')
                            ->label('Subtotal')
                            ->state(fn (Order $r) => Money::format($r->subtotal_rupiah)),

                        TextEntry::make('discount_rupiah')
                            ->label('Diskon')
                            ->state(fn (Order $r) => Money::format($r->discount_rupiah)),

                        TextEntry::make('ppn_rupiah')
                            ->label('PPN')
                            ->state(fn (Order $r) => Money::format($r->ppn_rupiah)),

                        TextEntry::make('total_rupiah')
                            ->label('Total')
                            ->weight('bold')
                            ->state(fn (Order $r) => Money::format($r->total_rupiah)),
                    ]),

                /*
                 * Where the goods are, and the paper that came with them.
                 *
                 * The surat jalan link appears only once the order has
                 * shipped — the controller refuses earlier anyway, and a
                 * button that leads to a refusal is worse than no button.
                 */
                Section::make('Pengiriman')
                    ->columns(3)
                    ->visible(fn (Order $record) => $record->shipped_at !== null
                        || $record->confirmed_at !== null)
                    ->schema([
                        TextEntry::make('shipped_at')
                            ->label('Dikirim')
                            ->dateTime('d/m/Y')
                            ->placeholder('Belum dikirim'),

                        TextEntry::make('completed_at')
                            ->label('Selesai')
                            ->dateTime('d/m/Y')
                            ->placeholder('—'),

                        TextEntry::make('warehouse.nama')
                            ->label('Dikirim dari')
                            ->placeholder('—'),

                        TextEntry::make('surat_jalan')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Order $record) => in_array(
                                $record->status,
                                [OrderStatus::Shipped, OrderStatus::Completed],
                                true,
                            ))
                            ->state('Buka surat jalan')
                            ->url(fn (Order $record) => route(
                                'portal.dokumen.surat-jalan',
                                ['order' => $record->id],
                            ))
                            ->openUrlInNewTab()
                            ->badge()
                            ->color('primary'),
                    ]),

                /*
                 * A buyer who placed one order and found two numbers in their
                 * history deserves to be told why, on both of them. The split
                 * is our warehousing problem, not theirs — so this says what
                 * happened in one sentence and links the pieces together.
                 */
                Section::make('Pesanan ini dikirim terpisah')
                    ->description('Barangnya diambil dari lebih dari satu gudang, jadi pesanan Anda '
                        .'dikirim dalam beberapa pengiriman dengan nomor masing-masing.')
                    ->visible(fn (Order $record) => app(OrderFamily::class)->isSplit($record))
                    ->schema([
                        View::make('filament.partials.pecahan-pengiriman')
                            ->viewData(['panel' => 'portal', 'tampilkanUang' => true]),
                    ]),

                Section::make('Catatan')
                    ->visible(fn (Order $record) => filled($record->catatan))
                    ->schema([
                        TextEntry::make('catatan')->hiddenLabel(),
                    ]),
            ]);
    }
}
