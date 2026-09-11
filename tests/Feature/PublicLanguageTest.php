<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Perusahaan;
use Tests\TestCase;

/**
 * The two languages cannot drift apart — that was the reason the site was
 * collapsed to English for a year, and this is the guard that lets it be
 * bilingual again.
 *
 * Two kinds of drift, both caught here rather than on the home page:
 * a chrome string with a translation on one side only, and a sentence about
 * the company with an `en` and no `id` (or the reverse). Either one renders
 * as a page that changes language mid-sentence, or as a blank.
 */
class PublicLanguageTest extends TestCase
{
    public function test_every_chrome_string_exists_in_both_languages(): void
    {
        $id = $this->flatten(require lang_path('id/publik.php'));
        $en = $this->flatten(require lang_path('en/publik.php'));

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($id), array_keys($en))),
            'keys in id/publik.php with no English twin',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($en), array_keys($id))),
            'keys in en/publik.php with no Indonesian twin',
        );

        // Nothing left as a placeholder on either side.
        foreach ([$id, $en] as $lang) {
            foreach ($lang as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "{$key} is blank");
            }
        }
    }

    public function test_every_bilingual_pair_in_the_company_copy_carries_both_sides(): void
    {
        $pairs = [];
        $this->collectPairs(config('perusahaan'), 'perusahaan', $pairs);

        $this->assertNotEmpty($pairs, 'the company copy is supposed to be bilingual');

        foreach ($pairs as $path => $pair) {
            $this->assertArrayHasKey('id', $pair, "{$path} has no Indonesian");
            $this->assertArrayHasKey('en', $pair, "{$path} has no English");
            $this->assertNotSame('', trim(is_array($pair['id']) ? implode('', $pair['id']) : (string) $pair['id']), "{$path}.id is blank");
            $this->assertNotSame('', trim(is_array($pair['en']) ? implode('', $pair['en']) : (string) $pair['en']), "{$path}.en is blank");
        }
    }

    public function test_the_company_copy_resolves_to_the_language_being_spoken(): void
    {
        app()->setLocale('id');
        $this->assertSame('Distributor grosir suku cadang otomotif', Perusahaan::text('tagline'));
        $this->assertStringStartsWith('Kami adalah', Perusahaan::list('profil')[0]);
        $this->assertSame('Komponen kelistrikan kendaraan.', Perusahaan::records('kategori')[2]['deskripsi']);

        app()->setLocale('en');
        $this->assertSame('Wholesale distributor of automotive spare parts', Perusahaan::text('tagline'));
        $this->assertStringStartsWith('We are', Perusahaan::list('profil')[0]);
        $this->assertSame('Vehicle electrical components.', Perusahaan::records('kategori')[2]['deskripsi']);

        // A record typed by the Owner in one language stays that sentence in both.
        $this->assertSame('Partner Name One', Perusahaan::records('mitra')[0]['nama']);
    }

    /** @return array<string, string> dotted key => leaf */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}.{$k}";

            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = (string) $v;
            }
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $pairs */
    private function collectPairs(mixed $node, string $path, array &$pairs): void
    {
        if (! is_array($node) || $node === []) {
            return;
        }

        $keys = array_keys($node);
        $languageKeyed = array_reduce($keys, fn (bool $c, $k) => $c && in_array($k, ['id', 'en'], true), true);

        if ($languageKeyed) {
            $pairs[$path] = $node;

            return;
        }

        foreach ($node as $k => $v) {
            $this->collectPairs($v, "{$path}.{$k}", $pairs);
        }
    }
}
