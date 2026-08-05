<?php

declare(strict_types=1);

namespace App\Filament\Portal\Concerns;

use App\Models\CustomerUser;
use RuntimeException;

/**
 * Buyers read; they do not edit.
 *
 * The one thing a buyer creates is an order, and that goes through
 * OrderStateMachine rather than a Filament create page — so there is no generic
 * CRUD anywhere in this panel, and no resource is one `->canCreate()` override
 * away from letting a customer edit their own invoice.
 */
trait ReadOnlyInPortal
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    protected static function buyer(): CustomerUser
    {
        $user = auth('customer')->user();

        if (! $user instanceof CustomerUser) {
            throw new RuntimeException('A portal resource was reached with no buyer in session.');
        }

        return $user;
    }
}
