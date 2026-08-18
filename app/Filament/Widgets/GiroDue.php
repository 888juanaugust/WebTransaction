<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Giro\GiroDirection;
use App\Domain\Money;
use App\Models\Giro;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: giro that can be banked today, and giro we must fund today.
 *
 * The other queues on this dashboard are about documents somebody has to act
 * on. This one is about a **date printed on a piece of paper** that nothing
 * else in the system watches, and missing it costs real money in both
 * directions: an incoming giro left in the drawer is cash that could already
 * be in the account, and an outgoing one presented against an account nobody
 * funded is our own cheque bouncing.
 *
 * Silent when there is nothing due, like the backup widget. A queue that is
 * always on screen stops being read.
 */
class GiroDue extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (auth()->user()?->role()->canHandleGiro() ?? false)
            && static::dueQuery()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Giro jatuh tempo')
            ->emptyStateHeading('Tidak ada giro yang jatuh tempo')
            ->query(static::dueQuery()->with(['company', 'supplier'])->orderBy('tanggal_jatuh_tempo'))
            ->columns([
                TextColumn::make('arah')
                    ->label('Arah')
                    ->badge()
                    ->formatStateUsing(fn (GiroDirection $state) => $state === GiroDirection::Masuk
                        ? 'Masuk'
                        : 'Keluar')
                    ->color(fn (GiroDirection $state) => $state === GiroDirection::Masuk
                        ? 'success'
                        : 'danger'),

                TextColumn::make('nomor_warkat')
                    ->label('Warkat')
                    ->searchable()
                    ->description(fn (Giro $record) => $record->bank_penerbit),

                TextColumn::make('pihak')
                    ->label('Pihak')
                    ->state(fn (Giro $record) => $record->counterpartyName()),

                TextColumn::make('tanggal_jatuh_tempo')
                    ->label('Jatuh tempo')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('nilai_rupiah')
                    ->label('Nilai')
                    ->alignEnd()
                    ->state(fn (Giro $record) => Money::format((int) $record->nilai_rupiah)),

                TextColumn::make('tindakan')
                    ->label('Yang perlu dilakukan')
                    ->state(fn (Giro $record) => $record->arah === GiroDirection::Masuk
                        ? 'Setor ke bank'
                        : 'Pastikan rekening terisi')
                    ->badge()
                    ->color(fn (Giro $record) => $record->arah === GiroDirection::Masuk
                        ? 'warning'
                        : 'danger'),
            ]);
    }

    /**
     * Outstanding, dated today or earlier, and still waiting on somebody.
     *
     * Incoming giros already taken to the bank drop out: the paper has left
     * the drawer and what remains is waiting for the bank, which is not this
     * queue's job. Outgoing ones stay until they clear, because until then the
     * money still has to be there.
     */
    private static function dueQuery()
    {
        return Giro::query()
            ->open()
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->toDateString())
            ->where(fn ($q) => $q
                ->where('arah', GiroDirection::Keluar->value)
                ->orWhereNull('tanggal_setor'));
    }
}
