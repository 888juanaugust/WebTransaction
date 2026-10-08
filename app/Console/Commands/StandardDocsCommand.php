<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Docs\StandardWriter;
use App\Domain\Pengaturan\Preferensi;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Writes docs/standard from the code; --check only says whether the pages are current. */
class StandardDocsCommand extends Command
{
    protected $signature = 'erp:standard {--check : Exit 1 when docs/standard differs from what the code would write}';

    protected $description = 'Regenerate docs/standard (the functional standard: every module, screen, field and report) from the Filament resources and pages';

    public function handle(): int
    {
        $dir = base_path('docs/standard');
        $pages = self::generate();

        $stale = [];
        foreach ($pages as $file => $markdown) {
            if (! is_file("{$dir}/{$file}") || file_get_contents("{$dir}/{$file}") !== $markdown) {
                $stale[] = $file;
            }
        }
        foreach (glob("{$dir}/*.md") ?: [] as $existing) {
            if (! isset($pages[basename($existing)])) {
                $stale[] = basename($existing).' (no longer generated)';
            }
        }

        if ($this->option('check')) {
            if ($stale === []) {
                $this->info('docs/standard is current.');

                return self::SUCCESS;
            }
            $this->warn('docs/standard is stale; run php artisan erp:standard. Differs: '.implode(', ', $stale));

            return self::FAILURE;
        }

        @mkdir($dir, 0775, true);
        foreach (glob("{$dir}/*.md") ?: [] as $existing) {
            if (! isset($pages[basename($existing)])) {
                unlink($existing);
            }
        }
        foreach ($pages as $file => $markdown) {
            file_put_contents("{$dir}/{$file}", $markdown);
        }
        $this->info(count($pages).' page(s) written to docs/standard'.($stale === [] ? ' (unchanged).' : '; changed: '.implode(', ', $stale).'.'));

        return self::SUCCESS;
    }

    /**
     * The pages as the code would write them, with every module switched on
     * for the duration (inside a transaction that is rolled back, so the
     * installation's own preferences are untouched).
     *
     * @return array<string, string>
     */
    public static function generate(): array
    {
        DB::beginTransaction();
        try {
            app(ModuleRegistry::class)->enableAll();

            return (new StandardWriter(base_path('docs/standard/_notes'), app(ModuleRegistry::class)))->pages();
        } finally {
            DB::rollBack();
            app(Preferensi::class)->forget();
        }
    }
}
