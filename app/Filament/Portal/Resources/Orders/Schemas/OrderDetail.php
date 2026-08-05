<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders\Schemas;

use App\Domain\Money;
use App\Domain\Orders\OrderStatus;
use App\Filament\Portal\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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

                Section::make('Catatan')
                    ->visible(fn (Order $record) => filled($record->catatan))
                    ->schema([
                        TextEntry::make('catatan')->hiddenLabel(),
                    ]),
            ]);
    }
}
