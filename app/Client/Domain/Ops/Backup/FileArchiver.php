<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Backup;

use PharData;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * The files a database restore cannot bring back: storage/app/private holds
 * the supplier price-list files kept for ever and the tax filings as they
 * went out. An uncompressed tar through PharData, so restoring needs
 * nothing but PHP; the cipher is what makes it unreadable, not gzip.
 */
class FileArchiver
{
    public function __construct(private readonly string $root) {}

    public static function fromConfig(): self
    {
        return new self((string) config('ops.backup.files_root'));
    }

    public function hasAnything(): bool
    {
        return is_dir($this->root) && Finder::create()->files()->in($this->root)->hasResults();
    }

    /** Returns the number of files archived; an archive of nothing is refused. */
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

    /** Unpacks over a directory, overwriting: a restore is one moment in time, not a merge of two. */
    public function extractTo(string $archivePath, string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException("Could not create {$destination}.");
        }
        (new PharData($archivePath))->extractTo($destination, null, true);
    }
}
