<?php

declare(strict_types=1);

namespace App\Filament\Resources\Giros\Tables;

use App\Domain\Giro\GiroDirection;
use App\Domain\Giro\GiroStatus;
use App\Domain\Money;
use App\Filament\Actions\GiroTransitionActions;
use App\Filament\Resources\Giros\GiroResource;
use App\Models\Giro;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The drawer, sorted by the date that matters.
 *
 * **Due date ascending, not newest first** — the only table here that is. A
 * giro register is a diary: what is bankable today is at the top, what is
 * months away is at the bottom, and the order is the worklist. Sorting by
 * entry date would bury a cheque that came due last week under one taken in
 * this morning.
 *
 * The date column carries its own warning. A giro past its date and still
 * unbanked is money that could already be in the account, and a giro banked
 * days ago with no answer is a phone call to the bank — two different jobs
 * that look identical in a status column, so the difference is spelled out
 * underneath the date.
 */
class GirosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal_jatuh_tempo', 'asc')
            ->recordUrl(fn (Giro $record) => GiroResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('arah')
                    ->label('Arah')
                    ->badge()
                    ->formatStateUsing(fn (GiroDirection $state) => $state === GiroDirection::Masuk
                        ? 'Masuk'
                        : 'Keluar')
                    ->color(fn (GiroDirection $state) => $state === GiroDirection::Masuk
                        ? 'success'
                        : 'gray'),

                TextColumn::make('nomor_warkat')
                    ->label('Warkat')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Giro $record) => $record->bank_penerbit),

                TextColumn::make('counterparty')
                    ->label('Pihak')
                    ->state(fn (Giro $record) => $record->counterpartyName())
                    ->description(fn (Giro $record) => $record->documentNumber()),

                TextColumn::make('nilai_rupiah')
                    ->label('Nilai')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Money::format((int) $state)),

                TextColumn::make('tanggal_jatuh_tempo')
                    ->label('Jatuh tempo')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Giro $record) => static::dueNote($record))
                    ->color(fn (Giro $record) => $record->isOverdue() ? 'warning' : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (GiroStatus $state) => $state->label())
                    ->color(fn (GiroStatus $state) => $state->color()),
            ])
            ->filters([
                SelectFilter::make('arah')->label('Arah')->options([
                    GiroDirection::Masuk->value => 'Masuk (dari pelanggan)',
                    GiroDirection::Keluar->value => 'Keluar (ke pemasok)',
                ]),

                SelectFilter::make('status')->label('Status')->options(
                    collect(GiroStatus::cases())
                        ->mapWithKeys(fn (GiroStatus $s) => [$s->value => $s->label()])
                        ->all()
                ),

                Filter::make('bisa_disetor')
                    ->label('Sudah bisa disetor')
                    ->query(fn (Builder $query) => $query
                        ->where('status', GiroStatus::Beredar->value)
                        ->where('arah', GiroDirection::Masuk->value)
                        ->whereNull('tanggal_setor')
                        ->whereDate('tanggal_jatuh_tempo', '<=', now()->toDateString())),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Lihat rincian'),
                    ...GiroTransitionActions::all(),
                ])->tooltip('Tindakan'),
            ])
            ->emptyStateHeading('Belum ada bilyet giro')
            ->emptyStateDescription(
                'Giro dari pelanggan dicatat lewat "Terima giro", dan giro yang kita serahkan '
                .'ke pemasok lewat "Terbitkan giro". Keduanya belum jadi uang sampai cair.'
            );
    }

    /**
     * The sentence under the date: what is actually waiting on what.
     *
     * A settled giro says nothing — the status badge beside it already has the
     * answer, and repeating it in two places is how the two end up disagreeing
     * after somebody edits one of them.
     */
    private static function dueNote(Giro $record): ?string
    {
        if (! $record->isOpen()) {
            return null;
        }

        if ($record->arah === GiroDirection::Keluar) {
            return $record->isOverdue()
                ? 'Belum dicairkan pemasok'
                : null;
        }

        if ($record->tanggal_setor !== null) {
            return 'Disetor '.$record->tanggal_setor->format('d/m/Y').', menunggu bank';
        }

        return $record->isDue()
            ? 'Sudah bisa disetor'
            : null;
    }
}
