<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Tables;

use App\Domain\Access\Role;
use App\Filament\Actions\StaffAccountActions;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Who works here and what they can do.
 *
 * Inactive accounts stay in the list rather than being filtered out by
 * default. A leaver's row is the evidence that they were shut off, and a list
 * that hides them answers "is Budi still able to log in?" with silence.
 */
class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Active first, then by name. The people who can currently log in
             * are the ones anybody came here to look at.
             */
            ->defaultSort('is_active', 'desc')
            ->emptyStateHeading('Belum ada staf lain')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->description(fn (User $record) => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),

                TextColumn::make('region.kode')
                    ->label('Wilayah')
                    ->badge()
                    ->color('info')
                    /*
                     * The Owner's blank is a grant, not an omission, and a
                     * blank cell would read as data entry someone forgot.
                     */
                    ->placeholder('Semua wilayah')
                    ->toggleable(),

                TextColumn::make('role')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn (Role $state) => $state->label())
                    ->color(fn (Role $state) => match ($state) {
                        Role::Owner => 'danger',
                        Role::Finance => 'warning',
                        Role::Warehouse => 'gray',
                        Role::Sales => 'info',
                    })
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Peran')
                    ->options(fn () => collect(Role::cases())
                        ->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),
                ActionGroup::make(StaffAccountActions::all()),
            ])
            /*
             * No bulk actions, and none of them would be delete anyway. Every
             * action here is one person's account changing, with a reason
             * attached; a checkbox column invites doing it to six people at
             * once with one reason covering all of them.
             */
            ->toolbarActions([]);
    }
}
