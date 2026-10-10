<?php

declare(strict_types=1);

namespace App\Client\Domain;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The staff identity the scheduler writes under: count sheets drafted
 * in the evening, nothing a person did. An operator in no group, with a
 * password nobody holds; under segregation of duties it never approves.
 */
final class SystemActor
{
    public static function email(): string
    {
        return (string) config('stock.system_email', 'system@central.local');
    }

    public static function user(): User
    {
        $user = User::query()->where('email', self::email())->first();
        if ($user === null) {
            $user = new User(['name' => 'System', 'email' => self::email(), 'access_type' => 'operator', 'is_active' => true]);
            $user->forceFill(['password' => Hash::make(Str::password(64))])->save();
        }

        return $user;
    }
}
