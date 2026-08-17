<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupCipher;
use Illuminate\Console\Command;

/**
 * Generate a backup key.
 *
 * Prints it and does nothing else — no writing to `.env`, no copying it
 * anywhere. Where this key ends up is the single most consequential decision
 * in the whole backup arrangement, and a command that quietly filed it
 * somewhere would make that decision for somebody who had not thought about
 * it yet.
 */
class BackupKeyCommand extends Command
{
    protected $signature = 'backup:key';

    protected $description = 'Generate an encryption key for backups';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  BACKUP_ENCRYPTION_KEY='.BackupCipher::generateKey());
        $this->newLine();

        $this->warn('  Read this before you paste it anywhere:');
        $this->line('  · Lose this key and every backup you hold is unreadable. There is no recovery.');
        $this->line('  · Store it somewhere that is NOT the backup destination. A key sitting in the');
        $this->line('    same bucket as the file it opens is a longer filename, not encryption.');
        $this->line('  · Changing it does not re-encrypt old backups. Keep the old key as long as you');
        $this->line('    keep backups written with it.');
        $this->newLine();
        $this->line('  docs/BACKUP.md has the rest.');
        $this->newLine();

        return self::SUCCESS;
    }
}
