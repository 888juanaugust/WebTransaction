<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\Schemas\StaffForm;
use App\Filament\Resources\Staff\Tables\StaffTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Staff accounts.
 *
 * Until this existed, hiring somebody meant a `php artisan tinker` session on
 * the production box, and a member of staff locked out of their own account
 * stayed locked out until a developer was free. Both are ordinary Tuesday
 * problems and neither should need SSH.
 *
 * Owner only, and this is the widest permission in the system by some margin.
 * Not because the screen shows anything sensitive — it shows four names — but
 * because it is the one place every other permission can be handed to
 * somebody. A Finance clerk who could give themselves the Sales role would
 * undo the rule keeping whoever confirms a payment away from the invoice
 * amount, in the time it takes to load one page. Every separation elsewhere is
 * worth exactly what this screen's access control is worth.
 *
 * This manages *staff*. Buyer logins are a different table behind a different
 * guard, and they are created from the company they belong to — a buyer
 * account with no company is an account scoped to nothing.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Staf';

    protected static ?string $modelLabel = 'staf';

    protected static ?string $pluralModelLabel = 'staf';

    protected static \UnitEnum|string|null $navigationGroup = 'Pengaturan';

    /** Above the audit log at 91: you grant access here, then read what came of it there. */
    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'staf';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(mixed $record): bool
    {
        return static::canViewAny();
    }

    /**
     * Nothing here deletes.
     *
     * `audit_logs.actor_id` references this table with ON DELETE NO ACTION, so
     * removing anybody who ever did anything would either fail at the database
     * or take the record of what they did with it. Leavers are switched off.
     */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return StaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }
}
