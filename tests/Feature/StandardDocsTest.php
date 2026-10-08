<?php

namespace Tests\Feature;

use App\Console\Commands\StandardDocsCommand;
use App\Filament\Modul;
use Tests\TestCase;

/** docs/standard is written from the code; a code change that moves a screen, column or field regenerates it. */
class StandardDocsTest extends TestCase
{
    public function test_the_standard_pages_are_current(): void
    {
        $this->seed();
        $stale = [];
        foreach (StandardDocsCommand::generate() as $file => $markdown) {
            $path = base_path("docs/standard/{$file}");
            if (! is_file($path) || file_get_contents($path) !== $markdown) {
                $stale[] = $file;
            }
        }

        $this->assertSame([], $stale, 'docs/standard is stale; run php artisan erp:standard.');
    }

    public function test_every_module_group_has_a_page_with_its_notes(): void
    {
        foreach (Modul::cases() as $modul) {
            $this->assertFileExists(base_path("docs/standard/{$modul->value}.md"));
            $this->assertFileExists(base_path("docs/standard/_notes/{$modul->value}.md"), "{$modul->value} has no hand-written notes");
        }
    }
}
