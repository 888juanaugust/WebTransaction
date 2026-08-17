<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One backup attempt, successful or not. */
#[Fillable([
    'started_at', 'finished_at', 'status', 'disk', 'database_path', 'files_path',
    'offsite', 'database_bytes', 'files_bytes', 'verified_bytes',
    'duration_seconds', 'catatan', 'error',
])]
class BackupRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    /**
     * Written **and read back**. There is no plain "succeeded" — an artefact
     * nobody has decrypted is a hypothesis, not a backup.
     */
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'offsite' => 'boolean',
            'database_bytes' => 'integer',
            'files_bytes' => 'integer',
            'verified_bytes' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function totalBytes(): int
    {
        return $this->database_bytes + $this->files_bytes;
    }
}
