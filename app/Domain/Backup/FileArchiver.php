<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use PharData;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * The files a database restore cannot bring back.
 *
 * `storage/app/private` holds the raw supplier price lists — kept forever,
 * because a published price has to be traceable to the file it came from — and
 * the faktur pajak exports exactly as they were filed. Neither can be
 * regenerated from the database: the first was never in it, and the second
 * would come back as whatever today's code produces rather than what was
 * actually uploaded to the tax office.
 *
 * So a database-only backup quietly loses the evidence and keeps the ledger,
 * which is the wrong half to lose.
 *
 * `PharData` rather than shelling out to `tar`: one less binary that has to
 * exist on both the machine that writes the archive and the machine that reads
 * it. Restoring one needs nothing but PHP.
 */
class FileArchiver
{
    public function __construct(
        private readonly string $root,
    ) {}

    public static function fromConfig(): self
    {
        return new self((string) config('backup.files_root'));
    }

    public function hasAnything(): bool
    {
        if (! is_dir($this->root)) {
            return false;
        }

        return Finder::create()->files()->in($this->root)->hasResults();
    }

    /**
     * Tar everything under the root into `$path`. Returns files archived.
     *
     * Uncompressed, because what is under there is already compressed —
     * spreadsheets are zip archives — and the whole thing gets encrypted
     * afterwards anyway, which compresses nothing.
     */
    public function archiveTo(string $path): int
    {
        if (! is_dir($this->root)) {
            throw new RuntimeException("Nothing to archive: {$this->root} does not exist.");
        }

        @unlink($path);

        $archive = new PharData($path);
        $count = 0;

        foreach (Finder::create()->files()->in($this->root)->sortByName() as $file) {
            $archive->addFile($file->getRealPath(), $file->getRelativePathname());
            $count++;
        }

        if ($count === 0) {
            @unlink($path);

            throw new RuntimeException('Refusing to write an archive with no files in it.');
        }

        return $count;
    }

    /**
     * Unpack an archive over a directory.
     *
     * Overwrites what is there. A restore is a deliberate act and the caller
     * has already been made to confirm it; merging instead would leave a mix
     * of two moments in time, which is harder to reason about than either.
     */
    public function extractTo(string $archivePath, string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException("Could not create {$destination}.");
        }

        (new PharData($archivePath))->extractTo($destination, null, true);
    }
}
