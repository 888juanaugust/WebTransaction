<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\FixedAssets\Pages;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\FixedAssets\DepreciationRun;
use App\Domain\Shared\Format;
use App\Filament\Resources\FixedAssets\FixedAssets\FixedAssetResource;
use App\Filament\Support\ListDocuments;
use App\Models\FixedAssets\FixedAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** The asset register, with the monthly depreciation run in its header. */
class ListFixedAssets extends ListDocuments
{
    protected static string $resource = FixedAssetResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'active' => Tab::make(__('In use'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', FixedAsset::ACTIVE)),
            'disposed' => Tab::make(__('Disposed'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', FixedAsset::DISPOSED)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            Action::make('runDepreciation')
                ->label(__('Run depreciation'))
                ->icon('heroicon-m-calculator')
                ->color('gray')
                // Posting depreciation changes the books: the update right on fixed assets, not just a look at them.
                ->visible(fn (): bool => app(HakAkses::class)->allows(auth()->user(), FixedAssetResource::menuKey(), Hak::Update))
                ->schema([
                    DatePicker::make('until')->label(__('Up to the month of'))->required()->native(false)->default(today()),
                ])
                ->requiresConfirmation()
                ->modalDescription(__('Posts one month of depreciation for every asset in use, for each month not yet posted up to that month. Months already posted are left alone.'))
                ->action(function (array $data): void {
                    $result = app(DepreciationRun::class)->upTo($data['until']);

                    Notification::make()
                        ->title(__(':posted month(s) posted', ['posted' => $result['posted']]))
                        ->body(__(':amount of depreciation', ['amount' => Format::money($result['amount'])]))
                        ->success()
                        ->send();

                    if ($result['skipped'] !== []) {
                        Notification::make()
                            ->title(__(':count asset(s) skipped', ['count' => count($result['skipped'])]))
                            ->body(implode("\n", $result['skipped']))
                            ->warning()
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn (): bool => static::getResource()::canCreate()),
        ];
    }
}
