<?php

namespace Database\Seeders\System;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The first administrator, when there is none with that address yet: the password comes from ADMIN_PASSWORD, or a
 * random one is made (printed once) and must be changed at first sign-in. An existing account is never touched, so
 * seeding again resets nobody's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL') ?: 'admin@example.test';
        if (User::query()->where('email', $email)->exists()) {
            return;
        }
        $password = env('ADMIN_PASSWORD') ?: Str::password(16, symbols: false);
        $admin = new User(['name' => env('ADMIN_NAME') ?: 'Administrator', 'email' => $email, 'access_type' => 'administrator', 'is_active' => true]);
        $admin->forceFill(['password' => Hash::make($password), 'password_change_required' => ! env('ADMIN_PASSWORD')])->save();
        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Administrator {$email}, password (shown once): {$password}");
        }
    }
}
