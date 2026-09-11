<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Domain\Money;
use App\Domain\Orders\OrderFamily;
use App\Domain\Orders\OrderStatus;
use App\Domain\Pricing\PriceReason;
use App\Models\Order;
use App\Models\OrderLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * One order, as it actually happened.
 *
 * This page used to render the *entry form*, disabled. It looked like a
 * detail screen and was not one: the form's helper text calls resolvePrice()
 * live, so a six-month-old order printed today's price beside lines the
 * customer was charged a different number for. Invariant 3 exists to stop
 * exactly that reading — everything below comes from the line snapshots and
 * nothing here touches the price list.
 *
 * It also showed no status, no dates, no tax, no invoice, and none of the
 * event log CLAUDE.md requires every transition to write — so the one screen
 * that could answer "what happened to this order" answered nothing.
 *
 * Money is assembled conditionally rather than hidden. canSeePrices() is true
 * for every role today, which makes the branch look like dead weight; it is
 * the one line that has to change when a role that must not see a price is
 * added, and a column that was never built cannot leak while somebody is
 * writing that line.
 */
class OrderDetail
{
    public static function configure(Schema $schema): Schema
    {
        $uang = auth()->user()?->role()->canSeePrices() ?? false;
        $kredit = auth()->user()?->role()->canSeeCreditData() ?? false;

        return $schema
            ->columns(1)
            ->components(array_values(array_filter([

                Section::make('Ringkasan')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('nomor')->label('Nomor'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                            ->color(fn (OrderStatus $state) => static::statusColor($state)),

                        TextEntry::make('company.nama')->label('Pelanggan')->placeholder('—'),

                        TextEntry::make('warehouse.nama')
                            ->label('Gudang')
                            ->placeholder('—')
                            // Which region's books this transaction sits in —
                            // after a split that is not always the customer's.
                            ->helperText(fn (Order $record) => $record->warehouse?->region?->kode),

                        TextEntry::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i'),

                        TextEntry::make('po_pelanggan')->label('PO pelanggan')->placeholder('—'),

                        /*
                         * The customer's sales seat, not `orders.sales_user_id`
                         * — that column records whoever typed the order in, and
                         * commission and every sales report are credited from
                         * the company's seat. Printing the other one beside the
                         * word "Sales" would be a second answer to a question
                         * the reports already answer.
                         */
                        TextEntry::make('company.salesUser.name')
                            ->label('Sales pelanggan')->placeholder('—'),

                        TextEntry::make('createdBy.name')
                            ->label('Dientri oleh')
                            ->state(fn (Order $record) => $record->placedInPortal()
                                ? ($record->placedByCustomerUser?->name.' (portal)')
                                : ($record->createdBy?->name ?? '—')),
                    ]),

                /*
                 * Only when there is a split to explain. A section headed
                 * "pecahan pengiriman" standing empty on every ordinary order
                 * teaches people to scroll past it on the one order it matters.
                 */
                Section::make('Pecahan pengiriman')
                    ->description('Barangnya tersebar, jadi order ini dipecah satu transaksi per gudang '
                        .'pengirim — masing-masing dibukukan di cabang gudangnya sendiri.')
                    ->visible(fn (Order $record) => app(OrderFamily::class)->isSplit($record))
                    ->schema([
                        View::make('filament.partials.pecahan-pengiriman')
                            ->viewData(['panel' => 'admin', 'tampilkanUang' => $uang]),
                    ]),

                Section::make('Baris order')
                    ->description($uang
                        ? 'Harga, diskon, DPP dan PPN di bawah ini adalah snapshot yang diambil saat '
                            .'order dikonfirmasi — bukan harga hari ini.'
                        : null)
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->columns($uang ? 12 : 6)
                            ->schema(array_values(array_filter([
                                TextEntry::make('sku')->label('Kode')->columnSpan(2),

                                TextEntry::make('description_snapshot')
                                    ->label('Barang')
                                    ->columnSpan(2)
                                    // The snapshot is the record of what was
                                    // sold; the product row is only a fallback
                                    // for lines written before those columns.
                                    ->state(fn (OrderLine $record) => trim(
                                        ($record->merk_snapshot ?? $record->product?->merk ?? '')
                                        .' '.($record->description_snapshot ?? $record->product?->description ?? '')
                                    ) ?: $record->sku),

                                TextEntry::make('ordered_qty')
                                    ->label('Jumlah')
                                    ->columnSpan(2)
                                    ->state(fn (OrderLine $record) => $record->ordered_qty.' '
                                        .$record->ordered_unit->label()
                                        .($record->ordered_unit->isBaseUnit()
                                            ? ''
                                            : " ({$record->qty_base} {$record->satuan_dasar_snapshot})")),

                                $uang ? TextEntry::make('unit_price_rupiah')
                                    ->label('Harga satuan')
                                    ->columnSpan(2)
                                    ->state(fn (OrderLine $record) => $record->isPriced()
                                        ? Money::format((int) round((float) $record->unit_price_rupiah))
                                        : 'belum dihargai')
                                    // Why it resolved that way — the second half
                                    // of what resolvePrice() returns, and the
                                    // answer to "why is this one cheaper".
                                    ->helperText(fn (OrderLine $record) => $record->isPriced()
                                        ? PriceReason::tryFrom((string) $record->price_reason)?->label()
                                        : null) : null,

                                $uang ? TextEntry::make('ppn_rupiah')
                                    ->label('DPP / PPN')
                                    ->columnSpan(2)
                                    ->state(fn (OrderLine $record) => $record->isPriced()
                                        ? Money::format((int) $record->dpp_rupiah).' / '
                                            .Money::format((int) $record->ppn_rupiah)
                                        : '—') : null,

                                $uang ? TextEntry::make('line_total_rupiah')
                                    ->label('Subtotal')
                                    // Two columns, not one: a rupiah figure
                                    // wraps mid-number in a twelfth of the
                                    // page, and "Rp 9.313.20 / 0" is worse
                                    // than no column at all.
                                    ->columnSpan(2)
                                    ->state(fn (OrderLine $record) => $record->isPriced()
                                        ? Money::format((int) $record->line_total_rupiah)
                                        : '—') : null,
                            ]))),
                    ]),

                $uang ? Section::make('Nilai order')
                    ->columns(5)
                    // An unconfirmed order has no total. Rp 0 would read as a
                    // free order rather than an unpriced one.
                    ->visible(fn (Order $record) => $record->confirmed_at !== null)
                    ->schema([
                        TextEntry::make('subtotal_rupiah')->label('Subtotal')
                            ->state(fn (Order $r) => Money::format((int) $r->subtotal_rupiah)),

                        TextEntry::make('discount_rupiah')->label('Diskon')
                            ->state(fn (Order $r) => Money::format((int) $r->discount_rupiah)),

                        TextEntry::make('dpp_rupiah')->label('DPP')
                            ->state(fn (Order $r) => Money::format((int) $r->dpp_rupiah))
                            ->helperText('11/12 × harga jual'),

                        TextEntry::make('ppn_rupiah')->label('PPN')
                            ->state(fn (Order $r) => Money::format((int) $r->ppn_rupiah)),

                        TextEntry::make('total_rupiah')->label('Total')->weight('bold')
                            ->state(fn (Order $r) => Money::format((int) $r->total_rupiah)),

                        TextEntry::make('price_list_version_id')
                            ->label('Versi daftar harga')
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->state(fn (Order $r) => $r->price_list_version_id === null
                                ? null
                                : '#'.$r->price_list_version_id
                                    .($r->priceListVersion?->effective_from
                                        ? ' — berlaku '.$r->priceListVersion->effective_from->format('d/m/Y')
                                        : '')),
                    ]) : null,

                Section::make('Perjalanan')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('submitted_at')->label('Diajukan')
                            ->dateTime('d/m/Y H:i')->placeholder('—'),

                        TextEntry::make('confirmed_at')->label('Disetujui')
                            ->dateTime('d/m/Y H:i')->placeholder('—'),

                        TextEntry::make('paid_at')->label('Lunas')
                            ->dateTime('d/m/Y H:i')->placeholder('—'),

                        TextEntry::make('shipped_at')->label('Dikirim')
                            ->dateTime('d/m/Y H:i')->placeholder('—'),

                        TextEntry::make('completed_at')->label('Selesai')
                            ->dateTime('d/m/Y H:i')->placeholder('—'),

                        /*
                         * Only while it can still expire. A reservation date
                         * on a shipped order is a date that already stopped
                         * meaning anything.
                         */
                        TextEntry::make('reservation_expires_at')
                            ->label('Reservasi stok kedaluwarsa')
                            ->dateTime('d/m/Y H:i')
                            ->columnSpanFull()
                            ->visible(fn (Order $r) => $r->reservation_expires_at !== null
                                && $r->status->holdsReservation()),
                    ]),

                $kredit ? Section::make('Faktur')
                    ->columns(4)
                    ->visible(fn (Order $record) => $record->invoice !== null)
                    ->schema([
                        TextEntry::make('invoice.nomor')->label('Nomor faktur'),

                        TextEntry::make('invoice.issued_on')->label('Diterbitkan')->date('d/m/Y'),

                        TextEntry::make('invoice.due_date')->label('Jatuh tempo')->date('d/m/Y'),

                        TextEntry::make('invoice.status')
                            ->label('Sisa tagihan')
                            // Summed from the append-only payment ledger, not
                            // read off a flag.
                            ->state(fn (Order $r) => Money::format((int) $r->invoice->amountOutstanding())),
                    ]) : null,

                Section::make('Catatan')
                    ->visible(fn (Order $record) => filled($record->catatan))
                    ->schema([
                        TextEntry::make('catatan')->hiddenLabel(),
                    ]),

                Section::make('Riwayat')
                    ->description('Setiap perpindahan status, siapa yang melakukannya, dan alasannya.')
                    ->collapsible()
                    ->schema([
                        View::make('filament.partials.riwayat-order'),
                    ]),
            ])));
    }

    /** Green is settled money; red means something went wrong. */
    public static function statusColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Completed, OrderStatus::Paid => 'success',
            OrderStatus::Rejected, OrderStatus::Expired => 'danger',
            OrderStatus::Draft => 'gray',
            default => 'warning',
        };
    }
}
