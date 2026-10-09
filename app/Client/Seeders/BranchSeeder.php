<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Models\Company\Branch;
use Illuminate\Database\Seeder;

/** Every branch needs a code for its document numbers; the head office gets PST (pusat) until the owner renames it. */
class BranchSeeder extends Seeder
{
    public const DEFAULT_CODE = 'PST';

    public function run(): void
    {
        $default = Branch::default();
        if ($default !== null && blank($default->code)) {
            $default->forceFill(['code' => self::DEFAULT_CODE])->saveQuietly();
        }
    }
}
