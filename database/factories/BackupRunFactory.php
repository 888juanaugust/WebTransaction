<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    public function definition(): array
    {
        return [
            'started_at' => now()->subMinutes(4),
            'finished_at' => now(),
            'status' => BackupRun::STATUS_VERIFIED,
            'disk' => 'backups',
            'database_path' => 'db/'.fake()->uuid().'.sql.enc',
            // Off by default, because that is the state that matters: a backup
            // sitting on the server it protects is the easy mistake.
            'offsite' => false,
            'database_bytes' => 4_194_304,
            'verified_bytes' => 4_194_304,
            'duration_seconds' => 240,
        ];
    }

    public function offsite(): static
    {
        return $this->state(fn () => ['offsite' => true]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => BackupRun::STATUS_FAILED,
            'error' => 'pg_dump exited with code 1',
            'verified_bytes' => null,
        ]);
    }
}
