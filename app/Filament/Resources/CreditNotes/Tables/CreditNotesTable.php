<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Domain\Billing\CreditNoteType;
use App\Filament\Actions\PostCreditNoteAction;
use App\Models\CreditNote;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Credit notes, newest first, drafts at the top of mind.
 *
 * A draft shows no money because it has none yet — every figure is computed at
 * posting. Showing a dash rather than "Rp 0" is the difference between "not
 * decided" and "decided to be nothing".
 */
class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->weight('medium'),

                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('company.nama')->label('Pelanggan')->searchable()->wrap(),

                TextColumn::make('invoice.nomor')->label('Faktur')->searchable(),

                TextColumn::make('jenis')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (CreditNoteType $state) => $state->label())
                    ->color(fn (CreditNoteType $state) => $state === CreditNoteType::ReturBarang ? 'warning' : 'gray'),

                TextColumn::make('total_rupiah')
                    ->label('Dikreditkan')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, CreditNote $record) => $record->isDraft()
                        ? '—'
                        : \App\Domain\Money::format((int) $state))
                    ->description(fn (CreditNote $record) => $record->isPosted() && $record->hpp_rupiah > 0
                        ? 'HPP kembali '.\App\Domain\Money::format((int) $record->hpp_rupiah)
                        : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === CreditNote::STATUS_POSTED ? 'Diposting' : 'Draf')
                    ->color(fn (string $state) => $state === CreditNote::STATUS_POSTED ? 'success' : 'gray'),

                TextColumn::make('alasan')->label('Alasan')->wrap()->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    CreditNote::STATUS_DRAFT => 'Draf',
                    CreditNote::STATUS_POSTED => 'Diposting',
                ]),
                SelectFilter::make('jenis')->label('Jenis')->options(
                    collect(CreditNoteType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()
                ),
            ])
            ->recordActions([
                PostCreditNoteAction::make(),

                Action::make('cetak')
                    ->label('Cetak')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (CreditNote $record) => route('dokumen.nota-kredit', $record))
                    ->openUrlInNewTab()
                    // A draft has no figures on it yet, so there is nothing to
                    // print that anybody should be handed.
                    ->visible(fn (CreditNote $record) => $record->isPosted()),

                EditAction::make()->visible(fn (CreditNote $record) => $record->isDraft()),
            ])
            ->emptyStateHeading('Belum ada nota kredit')
            ->emptyStateDescription('Nota kredit dibuat dari faktur yang barangnya sudah dikirim.');
    }
}
