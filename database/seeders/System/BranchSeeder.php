<?php

namespace Database\Seeders\System;

use App\Models\Company\Branch;
use Illuminate\Database\Seeder;

/** The head office: every document needs a branch, and a fresh company has this one. */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(['name' => 'Head Office'], [
            'is_default' => true,
            'used_all_user' => true,
            'is_active' => true,
        ]);
    }
}
