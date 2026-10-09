<?php

declare(strict_types=1);

namespace App\Client\Jobs;

use App\Client\Domain\Ops\Backup\BackupRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A backup asked for from the Operations screen, taken by the worker; one at a time, and a failure is a failed row, not a retry. */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public ?string $note = null) {}

    public function handle(BackupRunner $runner): void
    {
        $runner->run($this->note);
        $runner->prune();
    }
}
