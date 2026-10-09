<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Screens\CentralScreen;
use App\Filament\Support\ErpPage;
use App\Models\Inventory\Warehouse;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Warehouse Accounts: every warehouse with the one gudang account that
 * holds it. An administrator binds a member of the Warehouse group to a
 * warehouse (deactivating the old account first on a hand-over) or unbinds.
 */
class WarehouseAccounts extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.warehouse-accounts';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::WarehouseAccounts;
    }

    public function table(Table $table): Table
    {
        $binder = app(WarehouseBinder::class);

        return $table
            ->query(fn () => Warehouse::query()->with('branch')->where('is_system', false)->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label(__('Warehouse'))->weight('medium')->searchable(),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder('—'),
                TextColumn::make('holder')->label(__('Account'))->placeholder(__('nobody'))
                    ->state(fn (Warehouse $record) => $binder->holder($record)?->name),
                TextColumn::make('is_active')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? __('Active') : __('Inactive'))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('bind')->label(__('Bind account'))->icon('heroicon-m-user-plus')
                    ->visible(fn () => static::canUpdate() && auth()->user()?->isAdministrator())
                    ->modalHeading(fn (Warehouse $record) => __('Account of :name', ['name' => $record->name]))
                    ->modalDescription(__('One warehouse, one account: the user is bound to this warehouse alone and put in its branch.'))
                    ->schema([
                        Select::make('user_id')->label(__('Warehouse account'))->options(fn () => self::members())->searchable()->required()->native(false)
                            ->helperText(__('An active member of the Warehouse group.')),
                    ])
                    ->action(function (Warehouse $record, array $data) use ($binder): void {
                        try {
                            $binder->bind($record, User::query()->findOrFail((int) $data['user_id']), auth()->user());
                            Notification::make()->title(__(':name bound', ['name' => $record->name]))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot bind'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Action::make('unbind')->label(__('Unbind'))->icon('heroicon-m-user-minus')->color('gray')
                    ->visible(fn (Warehouse $record) => static::canUpdate() && auth()->user()?->isAdministrator() && $binder->holder($record) !== null)
                    ->requiresConfirmation()
                    ->action(function (Warehouse $record) use ($binder): void {
                        $holder = $binder->holder($record);
                        if ($holder !== null) {
                            $binder->unbind($holder, auth()->user());
                            Notification::make()->title(__(':name unbound', ['name' => $holder->name]))->success()->send();
                        }
                    }),
            ])
            ->emptyStateHeading(__('No warehouses yet'));
    }

    /** @return array<int, string> */
    private static function members(): array
    {
        return User::query()->where('is_active', true)
            ->whereHas('accessGroups', fn ($q) => $q->where('name', CentralGroups::WAREHOUSE))
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
