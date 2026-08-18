<?php

declare(strict_types=1);

namespace App\Filament\Resources\Giros\Schemas;

use App\Domain\Giro\GiroDirection;
use App\Domain\Giro\GiroStatus;
use App\Domain\Money;
use App\Models\Giro;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One giro, and what it has and has not done to the books.
 *
 * The section at the bottom is the point of this screen. "This is not a
 * payment" is the whole feature and it is the thing somebody looking at a
 * Rp 40 million cheque in their hand will most want reassuring about — so the
 * screen says it in words, per state, rather than leaving it to be inferred
 * from two account names.
 */
class GiroDetail
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Bilyet giro')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('nomor')->label('Nomor'),

                        TextEntry::make('arah')
                            ->label('Arah')
                            ->badge()
                            ->formatStateUsing(fn (GiroDirection $state) => $state->label())
                            ->color(fn (GiroDirection $state) => $state === GiroDirection::Masuk
                                ? 'success'
                                : 'gray'),

                        TextEntry::make('nomor_warkat')
                            ->label('Nomor warkat')
                            ->helperText(fn (Giro $record) => $record->bank_penerbit),

                        TextEntry::make('nilai_rupiah')
                            ->label('Nilai')
                            ->state(fn (Giro $record) => Money::format((int) $record->nilai_rupiah)),

                        TextEntry::make('counterparty')
                            ->label(fn (Giro $record) => $record->arah === GiroDirection::Masuk
                                ? 'Dari pelanggan'
                                : 'Ke pemasok')
                            ->state(fn (Giro $record) => $record->counterpartyName()),

                        TextEntry::make('dokumen')
                            ->label('Atas dokumen')
                            ->state(fn (Giro $record) => $record->documentNumber())
                            ->placeholder('Belum dicocokkan')
                            ->helperText(fn (Giro $record) => $record->documentNumber() === null
                                ? 'Saat cair, pembayarannya masuk antrean pencocokan.'
                                : null),

                        TextEntry::make('tanggal_terima')
                            ->label(fn (Giro $record) => $record->arah === GiroDirection::Masuk
                                ? 'Diterima'
                                : 'Diserahkan')
                            ->date('d/m/Y'),

                        TextEntry::make('tanggal_jatuh_tempo')->label('Jatuh tempo')->date('d/m/Y'),

                        TextEntry::make('tanggal_setor')
                            ->label('Disetor ke bank')
                            ->date('d/m/Y')
                            ->placeholder(fn (Giro $record) => $record->arah === GiroDirection::Keluar
                                ? 'Disetor oleh pemasok'
                                : 'Belum disetor')
                            ->visible(fn (Giro $record) => $record->arah === GiroDirection::Masuk),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (GiroStatus $state) => $state->label())
                            ->color(fn (GiroStatus $state) => $state->color()),

                        TextEntry::make('tanggal_selesai')
                            ->label('Tanggal selesai')
                            ->date('d/m/Y')
                            ->placeholder('—'),

                        /*
                         * Two different people and two different moments. The
                         * one label said "Dicatat oleh" over the *resolver*,
                         * so an outstanding giro showed an em dash where the
                         * person who took it in belongs — on a document whose
                         * point is that somebody accepted a piece of paper.
                         */
                        TextEntry::make('creator.name')->label('Dicatat oleh')->placeholder('—'),

                        TextEntry::make('resolvedBy.name')
                            ->label('Diselesaikan oleh')
                            ->placeholder('Belum selesai'),

                        TextEntry::make('alasan_selesai')
                            ->label('Keterangan')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('catatan')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Pengaruh ke buku')
                    ->schema([
                        TextEntry::make('efek')
                            ->hiddenLabel()
                            ->state(fn (Giro $record) => static::effect($record))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * What this giro has done to the ledger, in a sentence.
     *
     * Written per state rather than as one paragraph with caveats, because the
     * question somebody has in front of a giro is always about *this* one:
     * has it paid anything, and does the customer still owe me.
     */
    private static function effect(Giro $record): string
    {
        $nilai = Money::format((int) $record->nilai_rupiah);
        $masuk = $record->arah === GiroDirection::Masuk;

        return match ($record->status) {
            GiroStatus::Beredar => $masuk
                ? "{$nilai} dipindahkan dari Piutang Usaha ke Piutang Giro. Belum ada uang masuk, "
                    .'faktur masih terbuka, dan plafon kredit pelanggan belum kembali — giro bisa ditolak.'
                : "{$nilai} dipindahkan dari Utang Usaha ke Utang Giro. Uangnya masih di rekening, "
                    .'tapi sudah terikat tanggal jatuh tempo.',

            GiroStatus::Cair => $masuk
                ? "Giro dilepas dari Piutang Giro, lalu {$nilai} dicatat sebagai pembayaran masuk. "
                    .'Baru pada titik ini plafon kredit pelanggan kembali.'
                : "Giro dilepas dari Utang Giro, lalu {$nilai} dicatat sebagai pembayaran ke pemasok.",

            GiroStatus::Ditolak => $masuk
                ? "{$nilai} kembali ke Piutang Usaha. Tidak ada pembayaran yang perlu dibatalkan, "
                    .'karena memang belum pernah ada.'
                : "{$nilai} kembali ke Utang Usaha. Tagihan pemasok terbuka lagi.",

            GiroStatus::Dibatalkan => $masuk
                ? "{$nilai} kembali ke Piutang Usaha, seperti sebelum giro diterima."
                : "{$nilai} kembali ke Utang Usaha, seperti sebelum giro diserahkan.",
        };
    }
}
