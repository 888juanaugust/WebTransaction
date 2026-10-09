<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Support;

use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** A portal screen: the buyer reads; nothing here is created, edited or deleted by a buyer. */
abstract class PortalResource extends Resource
{
    /** A signed-in buyer reads every portal screen; the staff access matrix (the gate) is not consulted for a buyer. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? ($action->value ?? $action->name) : $action;

        return in_array($ability, ['view', 'viewAny'], true) && auth('customer')->check() ? Response::allow() : Response::deny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
