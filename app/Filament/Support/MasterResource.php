<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;

/**
 * A master-data screen: a list with the standard's columns and its
 * "Non Aktif" filter, and a form. Small masters manage records in a slide-over;
 * the big ones (customers, vendors, items, employees, accounts) have full pages.
 */
abstract class MasterResource extends ErpResource
{
    protected static ?string $recordTitleAttribute = 'name';

    /** The standard's "Non Aktif: Semua / Ya / Tidak" filter, active records by default. */
    public static function activeFilter(): TernaryFilter
    {
        return TernaryFilter::make('is_active')
            ->label(__('Active'))
            ->placeholder(__('All'))
            ->trueLabel('Active only')
            ->falseLabel('Inactive only')
            ->default(true);
    }

    public static function activeColumn(): IconColumn
    {
        return IconColumn::make('is_active')->label(__('fields.is_active'))->boolean();
    }

    public static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')->label(__('fields.is_active'))->default(true);
    }

    /**
     * The standard's "Daftar Pengguna" tab: everyone, or a chosen set of users. Who may use a record is an
     * administrator's to say (an operator could otherwise add themselves to a branch); for others it is read-only,
     * and a disabled list is not saved.
     */
    public static function usersTab(string $relationship = 'users'): Tab
    {
        $locked = fn (): bool => ! (auth()->user()?->isAdministrator() ?? false);

        return Tab::make(__('Users'))
            ->schema([
                Toggle::make('used_all_user')->label(__('fields.used_all_user'))->default(true)->live()->disabled($locked),
                CheckboxList::make($relationship)
                    ->label(__('fields.users'))
                    ->relationship($relationship, 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                    ->columns(3)
                    ->searchable()
                    ->disabled($locked)
                    ->visible(fn (Get $get): bool => ! $get('used_all_user')),
            ]);
    }

    /** "All users" or the names, as the standard's list column. */
    public static function usersColumn(string $relationship = 'users'): TextColumn
    {
        return TextColumn::make('used_all_user')
            ->label(__('fields.users'))
            ->state(fn ($record): string => $record->used_all_user
                ? __('All users')
                : $record->{$relationship}->pluck('name')->join(', '))
            ->limit(60);
    }

    /** Users of a master the logged-in operator may use: everyone's, or theirs. */
    public static function visibleToCurrentUser($query, string $relationship = 'users')
    {
        $user = auth()->user();
        if ($user === null || $user instanceof User && $user->isAdministrator()) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('used_all_user', true)
            ->orWhereHas($relationship, fn ($query) => $query->whereKey($user->id)));
    }
}
