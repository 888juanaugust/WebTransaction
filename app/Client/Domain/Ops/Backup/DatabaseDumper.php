<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * pg_dump and psql, with their failures noticed. The dump goes to a file,
 * not a pipe: a pipe hands good bytes downstream before pg_dump dies, and
 * the artefact is then a valid, authenticated, incomplete backup. Plain SQL
 * rather than the custom format, so any psql of any year can read it. The
 * password travels in the environment, never on the command line.
 */
class DatabaseDumper
{
    /** @param  array<string, mixed>  $connection */
    public function __construct(
        private readonly array $connection,
        private readonly string $pgDump,
        private readonly string $psql,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(?string $connection = null): self
    {
        $name = $connection ?? (string) config('database.default');
        $config = (array) config("database.connections.{$name}");
        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException(__('Backups dump PostgreSQL only; connection [:name] is ', ['name' => $name]).($config['driver'] ?? 'undefined').'.');
        }

        return new self($config, (string) config('ops.backup.pg_dump'), (string) config('ops.backup.psql'), (int) config('ops.backup.timeout_seconds'));
    }

    public function databaseName(): string
    {
        return (string) $this->connection['database'];
    }

    /** Dumps to a file and returns the bytes written; an empty dump is refused, not stored. */
    public function dumpTo(string $path): int
    {
        $process = new Process([
            $this->pgDump,
            '--host='.($this->connection['host'] ?? '127.0.0.1'),
            '--port='.($this->connection['port'] ?? 5432),
            '--username='.($this->connection['username'] ?? 'postgres'),
            '--dbname='.$this->databaseName(),
            '--no-password',
            '--clean', '--if-exists', '--no-owner', '--no-privileges',
            '--file='.$path,
        ], env: $this->environment(), timeout: $this->timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            @unlink($path);
            throw new RuntimeException(__('pg_dump failed: ').trim($process->getErrorOutput() ?: 'no error output'));
        }
        $bytes = (int) @filesize($path);
        if ($bytes === 0) {
            @unlink($path);
            throw new RuntimeException(__('pg_dump produced an empty file; that is not a backup.'));
        }

        return $bytes;
    }

    /** Feeds a plain-SQL dump into a database; ON_ERROR_STOP, so a restore that fails says so. */
    public function restoreFrom(string $path, ?string $intoDatabase = null): void
    {
        $this->psql('--dbname='.($intoDatabase ?? $this->databaseName()), '--file='.$path);
    }

    /** One statement against the maintenance database (CREATE DATABASE for a drill). */
    public function runOnServer(string $sql): void
    {
        $this->psql('--dbname=postgres', '--command='.$sql);
    }

    private function psql(string ...$arguments): void
    {
        $process = new Process([
            $this->psql,
            '--host='.($this->connection['host'] ?? '127.0.0.1'),
            '--port='.($this->connection['port'] ?? 5432),
            '--username='.($this->connection['username'] ?? 'postgres'),
            '--no-password', '--quiet', '--set=ON_ERROR_STOP=1',
            ...$arguments,
        ], env: $this->environment(), timeout: $this->timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(__('psql failed: ').trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return array_filter([
            'PGPASSWORD' => (string) ($this->connection['password'] ?? ''),
            'PGSSLMODE' => (string) ($this->connection['sslmode'] ?? 'prefer'),
        ], fn (string $v) => $v !== '');
    }
}
