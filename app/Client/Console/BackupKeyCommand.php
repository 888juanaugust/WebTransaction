<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Ops\Backup\BackupCipher;
use Illuminate\Console\Command;

/** Prints a fresh backup key. Writes nothing: the key goes in .env and in a second place off the server. */
class BackupKeyCommand extends Command
{
    protected $signature = 'central:backup-key';

    protected $description = 'Print a new BACKUP_ENCRYPTION_KEY';

    public function handle(): int
    {
        $this->line('BACKUP_ENCRYPTION_KEY='.BackupCipher::generateKey());
        $this->newLine();
        $this->warn('Put it in .env and keep a copy somewhere that is not this server: without the key the backups are noise.');
        $this->warn('Changing the key makes every earlier backup unreadable; keep the old key until those are pruned.');

        return self::SUCCESS;
    }
}
