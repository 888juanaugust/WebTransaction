<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Domain\SystemActor;
use Illuminate\Database\Seeder;

/** The System user the scheduler writes count sheets under. */
class SystemUserSeeder extends Seeder
{
    public function run(): void
    {
        SystemActor::user();
    }
}
