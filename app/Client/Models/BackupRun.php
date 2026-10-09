<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One backup attempt: running, verified (read back and decrypted) or failed, with what it wrote and where. */
class BackupRun extends Model implements HasAuditReference
{
    public const RUNNING = 'running';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    protected $table = 'backup_runs';

    protected $fillable = ['started_at', 'finished_at', 'status', 'disk', 'database_path', 'files_path', 'offsite', 'database_bytes', 'files_bytes', 'verified_bytes', 'duration_seconds', 'note', 'error'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'offsite' => 'boolean', 'database_bytes' => 'integer', 'files_bytes' => 'integer', 'verified_bytes' => 'integer', 'duration_seconds' => 'integer'];
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::VERIFIED);
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function totalBytes(): int
    {
        return (int) $this->database_bytes + (int) $this->files_bytes;
    }

    public function auditReference(): string
    {
        return (string) ($this->database_path ?? $this->id);
    }
}
