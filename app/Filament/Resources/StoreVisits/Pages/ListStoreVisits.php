<?php

declare(strict_types=1);

namespace App\Filament\Resources\StoreVisits\Pages;

use App\Domain\Access\Role;
use App\Domain\Visits\StoreVisits;
use App\Filament\Resources\StoreVisits\StoreVisitResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListStoreVisits extends ListRecords
{
    protected static string $resource = StoreVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Catat kunjungan')
                ->visible(fn () => StoreVisitResource::canCreate()),

            /*
             * The escape hatch in front of the 2-month purge: a month's
             * photos and their CSV, zipped, for whoever files these away.
             */
            Action::make('arsip')
                ->label('Arsip bulan')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->visible(fn () => in_array(auth()->user()?->role(), [Role::Owner, Role::Finance], true))
                ->schema([
                    Select::make('bulan')
                        ->label('Bulan')
                        ->options(collect(range(0, 5))
                            ->mapWithKeys(function (int $back) {
                                $bulan = now()->subMonthsNoOverflow($back)->startOfMonth();

                                return [$bulan->format('Y-m') => $bulan->translatedFormat('F Y')];
                            })
                            ->all())
                        ->default(now()->format('Y-m'))
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data) {
                    try {
                        $zipPath = app(StoreVisits::class)->archiveMonth(
                            Carbon::createFromFormat('Y-m', $data['bulan'])->startOfMonth(),
                            auth()->user(),
                        );

                        return response()->download($zipPath);
                    } catch (DomainException $e) {
                        Notification::make()
                            ->title('Tidak bisa diarsipkan')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return null;
                    }
                }),
        ];
    }
}
