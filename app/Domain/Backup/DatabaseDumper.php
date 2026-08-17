<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Running `pg_dump`, and noticing when it fails.
 *
 * Written to a plain file rather than piped straight into the cipher. Piping
 * would save a temp file and lose the one thing worth having: if `pg_dump`
 * dies part way, the pipe has already handed good bytes downstream and the
 * encrypted artefact is a valid, authenticated, *incomplete* backup. Dumping
 * first means a failure is a failure.
 *
 * The password goes in the environment rather than the command line, because
 * anyone on the box can read `ps`.
 */
class DatabaseDumper
{
    public function __construct(
        private readonly array $connection,
        private readonly string $pgDump,
        private readonly string $psql,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(?string $connection = null): self
    {
        $name = $connection ?? config('database.default');
        $config = config("database.connections.{$name}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException(
                "Backups only know how to dump PostgreSQL; connection [{$name}] is "
                .($config['driver'] ?? 'undefined').'.'
            );
        }

        return new self(
            connection: $config,
            pgDump: (string) config('backup.pg_dump'),
            psql: (string) config('backup.psql'),
            timeout: (int) config('backup.timeout_seconds'),
        );
    }

    public function databaseName(): string
    {
        return (string) $this->connection['database'];
    }

    /**
     * Dump to a file, and return the bytes written.
     *
     * Plain SQL rather than the custom format. Custom is smaller and restores
     * in parallel, but it can only be read by a `pg_restore` of a compatible
     * version — and the machine that has to read this is, by definition, not
     * the machine that wrote it. Plain SQL through `psql` will still work in
     * five years on whatever is to hand, and gzip recovers most of the size.
     */
    public function dumpTo(string $path): int
    {
        $process = new Process(
            [
                $this->pgDump,
                '--host='.($this->connection['host'] ?? '127.0.0.1'),
                '--port='.($this->connection['port'] ?? 5432),
                '--username='.($this->connection['username'] ?? 'postgres'),
                '--dbname='.$this->databaseName(),
                '--no-password',
                // So a restore into an existing database replaces it cleanly
                // rather than colliding with what is already there.
                '--clean',
                '--if-exists',
                // Roles and tablespaces belong to the server, not this
                // database, and restoring them needs superuser rights the
                // restoring account may not have.
                '--no-owner',
                '--no-privileges',
                /*
                 * pg_dump writes the file itself rather than us piping its
                 * stdout. Symfony buffers a process's output in memory even
                 * when a callback is given, so piping a multi-gigabyte dump
                 * through it would exhaust the PHP memory limit on exactly the
                 * night the database got big enough to matter.
                 */
                '--file='.$path,
            ],
            env: $this->environment(),
            timeout: $this->timeout,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException(
                'pg_dump failed: '.trim($process->getErrorOutput() ?: 'no error output')
            );
        }

        $bytes = (int) @filesize($path);

        /*
         * pg_dump can exit zero having produced nothing — a wrong database
         * name against a server that has one, for instance. Writing that
         * would replace a good backup with a valid empty one, which is the
         * failure that only shows up when somebody needs the backup.
         */
        if ($bytes === 0) {
            @unlink($path);

            throw new RuntimeException('pg_dump produced an empty file. Refusing to call that a backup.');
        }

        return $bytes;
    }

    /**
     * Feed a plain-SQL dump back into a database.
     *
     * `ON_ERROR_STOP` is the whole point. Without it `psql` runs every
     * statement it can, reports success, and leaves a database that is missing
     * whatever failed — a restore that looks like it worked is worse than one
     * that plainly did not.
     */
    public function restoreFrom(string $path, ?string $intoDatabase = null): void
    {
        $process = new Process(
            [
                $this->psql,
                '--host='.($this->connection['host'] ?? '127.0.0.1'),
                '--port='.($this->connection['port'] ?? 5432),
                '--username='.($this->connection['username'] ?? 'postgres'),
                '--dbname='.($intoDatabase ?? $this->databaseName()),
                '--no-password',
                '--quiet',
                '--set=ON_ERROR_STOP=1',
                '--file='.$path,
            ],
            env: $this->environment(),
            timeout: $this->timeout,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /** Run one statement against the maintenance database. */
    public function runOnServer(string $sql): void
    {
        $process = new Process(
            [
                $this->psql,
                '--host='.($this->connection['host'] ?? '127.0.0.1'),
                '--port='.($this->connection['port'] ?? 5432),
                '--username='.($this->connection['username'] ?? 'postgres'),
                '--dbname=postgres',
                '--no-password',
                '--quiet',
                '--set=ON_ERROR_STOP=1',
                '--command='.$sql,
            ],
            env: $this->environment(),
            timeout: $this->timeout,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        // PGPASSWORD rather than an argument: anyone on the box can read `ps`.
        return array_filter([
            'PGPASSWORD' => (string) ($this->connection['password'] ?? ''),
            'PGSSLMODE' => (string) ($this->connection['sslmode'] ?? 'prefer'),
        ], fn (string $value) => $value !== '');
    }
}
