<?php

declare(strict_types=1);

namespace App\Client\Portal;

use App\Models\Company\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The staff account in whose name the portal writes. The base stamps what it
 * writes with the signed-in staff user (created_by, audit, revisions,
 * approval requests, reservations) and types its guards to one; a buyer is
 * not one. So the portal's writes run as the Portal user on the web guard,
 * set for the work and taken off again, never signed in. The buyer stays on
 * the order (placed_by_customer_user_id) and in the audit meta.
 */
final class PortalActor
{
    public static function email(): string
    {
        return (string) config('portal.actor_email', 'portal@central.local');
    }

    public static function user(): User
    {
        $user = User::query()->where('email', self::email())->first();
        if ($user === null || ! $user->is_active) {
            throw new RuntimeException(__('The Portal user is missing or inactive; seed it (php artisan db:seed) before buyers order.'));
        }
        // Every branch, so a buyer of any branch orders; a branch added since the seed is picked up here.
        $missing = array_diff(Branch::query()->pluck('id')->map(fn ($id) => (int) $id)->all(), $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all());
        if ($missing !== []) {
            $user->branches()->syncWithoutDetaching($missing);
        }

        return $user;
    }

    /** Runs the work as the Portal user on the web guard, then restores the customer guard. */
    public static function run(callable $work): mixed
    {
        $portal = self::user();
        $previousGuard = Auth::getDefaultDriver();
        $web = Auth::guard('web');
        $previousUser = $web->user();
        $web->setUser($portal);
        Auth::shouldUse('web');
        try {
            return $work($portal);
        } finally {
            if ($previousUser !== null) {
                $web->setUser($previousUser);
            } else {
                $web->forgetUser();
            }
            Auth::shouldUse($previousGuard);
        }
    }
}
