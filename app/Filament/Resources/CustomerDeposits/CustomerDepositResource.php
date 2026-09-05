<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerDeposits;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\CustomerDeposits\Pages\ListCustomerDeposits;
use App\Filament\Resources\CustomerDeposits\Tables\CustomerDepositsTable;
use App\Models\CustomerDeposit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Uang muka pelanggan — money in before anything is owed.
 *
 * Under Penjualan rather than Buku besar, because the people who take deposits
 * are the people who deal with customers. The accounting consequence is real
 * but it is not what anybody comes to this screen for.
 */
class CustomerDepositResource extends Resource
{
    protected static ?string $model = CustomerDeposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::KEUANGAN;

    protected static ?string $navigationLabel = 'Uang muka';

    protected static ?string $modelLabel = 'uang muka';

    protected static ?string $pluralModelLabel = 'uang muka';

    protected static ?int $navigationSort = 26;

    protected static ?string $slug = 'uang-muka';

    protected static ?string $recordTitleAttribute = 'nomor';

    /**
     * The same permission as confirming a payment, and for the same reason:
     * saying money arrived is the act being controlled. Warehouse must not see
     * it at all — it is customer money data.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    /** Taken through the header action, which asks where the money landed. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * A deposit is money that arrived. Editing the amount would mean editing
     * what the bank did — corrections are refunds.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Money sitting on account that no invoice has claimed.
     *
     * Not an error, but not nothing either: a deposit nobody applies is a
     * customer who thinks they have paid and an invoice that says otherwise,
     * and the phone call comes eventually.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $held = CustomerDeposit::query()->held()->count();

        return $held > 0 ? (string) $held : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return CustomerDepositsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomerDeposits::route('/')];
    }
}
