<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Tagihan\Schemas;

use App\Domain\Money;
use App\Models\Invoice;
use App\Models\VirtualAccount;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One invoice, and — the reason a buyer opens this screen — where to pay it.
 */
class TagihanDetail
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Cara pembayaran')
                    ->description(
                        'Transfer ke Virtual Account di bawah ini. Nomor VA bersifat tetap, '
                        .'jadi nomor yang sama bisa dipakai untuk setiap pembayaran.'
                    )
                    ->columns(3)
                    // Only while there is something left to pay.
                    ->visible(fn (Invoice $record) => $record->amountOutstanding() > 0)
                    ->schema([
                        TextEntry::make('va_bank')
                            ->label('Bank')
                            ->state(fn (Invoice $r) => self::virtualAccount($r)?->bank_code ?? '—'),

                        TextEntry::make('va_nomor')
                            ->label('Nomor Virtual Account')
                            ->weight('bold')
                            ->copyable()
                            ->copyMessage('Nomor VA disalin')
                            ->state(fn (Invoice $r) => self::virtualAccount($r)?->account_number
                                ?? 'Hubungi kami untuk nomor VA'),

                        TextEntry::make('va_jumlah')
                            ->label('Jumlah yang harus dibayar')
                            ->weight('bold')
                            ->state(fn (Invoice $r) => Money::format($r->amountOutstanding())),
                    ]),

                Section::make('Rincian faktur')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('nomor')->label('Nomor faktur'),
                        TextEntry::make('order.nomor')->label('Pesanan')->placeholder('—'),
                        TextEntry::make('issued_on')->label('Tanggal faktur')->date('d/m/Y'),
                        TextEntry::make('due_date')->label('Jatuh tempo')->date('d/m/Y'),

                        TextEntry::make('subtotal_rupiah')
                            ->label('Subtotal')
                            ->state(fn (Invoice $r) => Money::format($r->subtotal_rupiah)),

                        TextEntry::make('dpp_rupiah')
                            ->label('DPP')
                            ->state(fn (Invoice $r) => Money::format($r->dpp_rupiah)),

                        TextEntry::make('ppn_rupiah')
                            ->label('PPN')
                            ->state(fn (Invoice $r) => Money::format($r->ppn_rupiah)),

                        TextEntry::make('total_rupiah')
                            ->label('Total')
                            ->weight('bold')
                            ->state(fn (Invoice $r) => Money::format($r->total_rupiah)),
                    ]),

                Section::make('Pembayaran diterima')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('dibayar')
                            ->label('Sudah dibayar')
                            ->state(fn (Invoice $r) => Money::format($r->amountPaid())),

                        TextEntry::make('sisa')
                            ->label('Sisa tagihan')
                            ->weight('bold')
                            ->state(fn (Invoice $r) => Money::format($r->amountOutstanding()))
                            ->color(fn (Invoice $r) => $r->amountOutstanding() > 0 ? 'danger' : 'success'),
                    ]),

                Section::make('Data pajak')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('npwp')->label('NPWP')->placeholder('—'),
                        TextEntry::make('nama_wajib_pajak')->label('Nama wajib pajak')->placeholder('—'),
                        TextEntry::make('alamat_pajak')->label('Alamat pajak')->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * The company's fixed virtual account.
     *
     * Fixed rather than per-invoice, so a buyer paying three invoices sends
     * three transfers to the same number and finance matches them by amount and
     * reference.
     */
    private static function virtualAccount(Invoice $invoice): ?VirtualAccount
    {
        return $invoice->company
            ?->virtualAccounts()
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }
}
