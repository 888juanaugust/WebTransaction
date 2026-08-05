<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Bahasa Indonesia is the UI language, and Filament's bundled `id` translation
 * has holes in it.
 *
 * When a key is missing Laravel does not fall back to English — it renders the
 * key itself, so a table footer reads "filament-tables::table.result_count" and
 * a section's collapse button announces "filament-schemas::components.section.
 * actions.collapse.label" to a screen reader. Thirty-six of those were live in
 * both panels before this test existed, most of them accessibility labels,
 * which is exactly why nobody had noticed.
 *
 * The gaps are patched in lang/vendor/. This asserts the patch is complete, and
 * fails the next time a Filament upgrade adds an English key with no Indonesian
 * counterpart — the point being that the requirement is derived from the
 * packages rather than from a list somebody has to remember to update.
 */
class IndonesianTranslationCoverageTest extends TestCase
{
    /**
     * Namespace => path to the package's bundled translations.
     *
     * @return array<string, string>
     */
    private static function packages(): array
    {
        return [
            'filament-panels' => 'vendor/filament/filament/resources/lang',
            'filament-tables' => 'vendor/filament/tables/resources/lang',
            'filament-actions' => 'vendor/filament/actions/resources/lang',
            'filament-forms' => 'vendor/filament/forms/resources/lang',
            'filament-schemas' => 'vendor/filament/schemas/resources/lang',
        ];
    }

    public function test_every_english_filament_key_has_an_indonesian_one(): void
    {
        $missing = [];

        foreach (self::packages() as $namespace => $path) {
            $base = base_path($path);

            if (! is_dir($base)) {
                $this->fail("{$namespace} is not installed at {$path} — this test is looking in the wrong place.");
            }

            $english = self::keysIn("{$base}/en");
            $indonesian = self::keysIn("{$base}/id")
                + self::keysIn(base_path("lang/vendor/{$namespace}/id"));

            foreach (array_diff_key($english, $indonesian) as $key => $_) {
                $missing[] = "{$namespace}::{$key}";
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These keys render as raw translation keys in the UI. Add them to lang/vendor/:\n  "
            .implode("\n  ", $missing)
        );
    }

    /**
     * The sweep has to actually be finding keys — an empty English set would
     * make the assertion above pass without proving anything.
     */
    public function test_the_sweep_reads_real_translation_files(): void
    {
        foreach (self::packages() as $namespace => $path) {
            $this->assertNotEmpty(
                self::keysIn(base_path("{$path}/en")),
                "Found no English keys for {$namespace}."
            );
        }
    }

    /**
     * Deliberate replacements of a translation Filament already ships.
     *
     * Each one is a decision about wording, so each one is listed here with a
     * reason. Anything not on this list is an accident.
     *
     * @var list<string>
     */
    private const DELIBERATE_OVERRIDES = [
        // Filament says "Dasbor". Staff say "Dashboard" — the same reasoning
        // that keeps `surat jalan` and `gudang` in Indonesian: use the word
        // people actually say. The rule cuts both ways.
        'filament-panels::pages.dashboard.title',
    ];

    /**
     * Our overrides fill gaps, except where we meant to change the wording.
     *
     * Copying a whole upstream file into lang/vendor/ freezes translations we
     * did not write, so they stop improving when Filament does. This catches
     * that, while letting a deliberate rewording through as long as somebody
     * writes down why.
     */
    public function test_our_overrides_only_fill_gaps_or_are_declared_deliberate(): void
    {
        $undeclared = [];

        foreach (self::packages() as $namespace => $path) {
            $bundled = self::keysIn(base_path("{$path}/id"));

            foreach (self::keysIn(base_path("lang/vendor/{$namespace}/id")) as $key => $_) {
                $qualified = "{$namespace}::{$key}";

                if (array_key_exists($key, $bundled)
                    && ! in_array($qualified, self::DELIBERATE_OVERRIDES, true)) {
                    $undeclared[] = $qualified;
                }
            }
        }

        sort($undeclared);

        $this->assertSame(
            [],
            $undeclared,
            'These overrides replace a translation Filament already ships, which freezes it. '
            ."Either delete them, or add them to DELIBERATE_OVERRIDES with a reason:\n  "
            .implode("\n  ", $undeclared)
        );
    }

    /**
     * A declared override must still actually be overriding something —
     * otherwise the list rots into a record of keys that no longer exist.
     */
    public function test_every_declared_override_is_real(): void
    {
        foreach (self::DELIBERATE_OVERRIDES as $qualified) {
            [$namespace, $key] = explode('::', $qualified, 2);

            $this->assertArrayHasKey(
                $key,
                self::keysIn(base_path("lang/vendor/{$namespace}/id")),
                "{$qualified} is declared as a deliberate override but we do not override it."
            );

            $this->assertArrayHasKey(
                $key,
                self::keysIn(base_path(self::packages()[$namespace].'/id')),
                "{$qualified} is declared as a deliberate override but Filament no longer ships that key."
            );
        }
    }

    /**
     * Every translation key in a directory, flattened to "file.a.b.c".
     *
     * @return array<string, mixed>
     */
    private static function keysIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $keys = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = trim(str_replace($directory, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $prefix = str_replace(['.php', DIRECTORY_SEPARATOR], ['', '.'], $relative);

            $keys += self::flatten(require $file->getPathname(), $prefix);
        }

        return $keys;
    }

    /**
     * @param  array<mixed>  $lines
     * @return array<string, mixed>
     */
    private static function flatten(array $lines, string $prefix): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += self::flatten($value, $full);

                continue;
            }

            $flat[$full] = $value;
        }

        return $flat;
    }
}
