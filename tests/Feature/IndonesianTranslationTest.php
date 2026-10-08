<?php

namespace Tests\Feature;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Filament\Support\ErpPage;
use App\Filament\Support\ErpResource;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** The Indonesian UI is complete: every string the code translates has its Indonesian, and no screen shows a string without one. */
class IndonesianTranslationTest extends TestCase
{
    /** Strings that read the same in Indonesian. */
    private const SAME = ['Total', 'Status', 'Email', 'FOB', 'NPWP', 'NIK', 'PTKP', 'TER', 'PKP', 'DPP', 'NSFP', 'KLU', 'Coretax PDF', 'Subtotal', 'Debit', 'Rate', 'Administrator', 'Operator', 'Data', 'Bank', 'Giro', 'PDF', 'Excel', 'Memo', 'Filter', 'Transfer', 'Online', 'Item',
        'Info', 'Final', 'Agenda', 'Minimum', 'WhatsApp', 'Virtual account', 'Check-in', 'Letter', 'Format', 'Reset', 'Posting', 'UPC / barcode', 'JKK :rate%', 'JKM :rate%', ':number :refusal', 'PO :number'];

    /** @return array<string, true> every key the code passes to __(), as tools/i18n/extract-strings.mjs collects them */
    private function keys(): array
    {
        $keys = [];
        $root = dirname(__DIR__, 2);
        foreach ((new Finder)->files()->in([$root.'/app', $root.'/resources/views'])->name('*.php') as $file) {
            $src = $file->getContents();
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $sq);
            preg_match_all('/__\\(\\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $dq);
            preg_match_all("/\\\$(?:plural)?[mM]odelLabel\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $ml);
            foreach ([...$sq[1], ...$ml[1]] as $k) {
                $keys[str_replace(["\\'", '\\\\'], ["'", '\\'], $k)] = true;
            }
            foreach ($dq[1] as $k) {
                $keys[str_replace(['\\"', '\\\\'], ['"', '\\'], $k)] = true;
            }
        }

        return array_filter($keys, fn ($v, string $k) => preg_match('/^(menu|fields|status)\.[\w.-]+$/', $k) !== 1, ARRAY_FILTER_USE_BOTH);
    }

    public function test_every_string_has_its_indonesian(): void
    {
        $id = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/id.json'), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];
        $untranslated = [];
        $placeholders = [];
        foreach (array_keys($this->keys()) as $key) {
            $value = $id[$key] ?? '';
            if ($value === '') {
                $missing[] = $key;

                continue;
            }
            if ($value === $key && ! in_array($key, self::SAME, true) && preg_match('/[a-z]{3,}/', $key) === 1) {
                $untranslated[] = $key;
            }
            preg_match_all('/:[a-zA-Z_]+/', $key, $a);
            preg_match_all('/:[a-zA-Z_]+/', $value, $b);
            sort($a[0]);
            sort($b[0]);
            if ($a[0] !== $b[0]) {
                $placeholders[] = $key;
            }
        }
        $this->assertSame([], $missing, 'Without Indonesian (run node tools/i18n/extract-strings.mjs id, then translate):');
        $this->assertSame([], $untranslated, 'Left in English:');
        $this->assertSame([], $placeholders, 'Placeholders differ:');
    }

    public function test_the_string_groups_match_english(): void
    {
        $flatten = function (array $a, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($a as $k => $v) {
                $out = array_merge($out, is_array($v) ? $flatten($v, "{$prefix}{$k}.") : ["{$prefix}{$k}"]);
            }

            return $out;
        };
        foreach (['menu', 'fields', 'status'] as $group) {
            $en = $flatten(require dirname(__DIR__, 2)."/lang/en/{$group}.php");
            $id = $flatten(require dirname(__DIR__, 2)."/lang/id/{$group}.php");
            $this->assertSame([], array_values(array_diff($en, $id)), "lang/id/{$group}.php lacks keys");
        }
    }

    public function test_every_screen_shows_in_indonesian(): void
    {
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();
        app(Preferensi::class)->set(PreferensiKey::Language, 'id');
        $missing = [];
        Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) use (&$missing) {
            if ($locale === 'id' && ! str_contains($key, '::') && preg_match('/[A-Z ]/', $key) === 1 && preg_match('/^[a-z_]+\.[\w.-]+$/', $key) !== 1) {
                $missing[$key] = true;
            }

            return $key;
        });

        $panel = Filament::getPanel('admin');
        $urls = [];
        foreach ($panel->getResources() as $resource) {
            if (is_subclass_of($resource, ErpResource::class)) {
                $urls[] = $resource::getUrl('index');
                if ($resource::hasPage('create')) {
                    $urls[] = $resource::getUrl('create');
                }
            }
        }
        foreach ($panel->getPages() as $page) {
            if (is_subclass_of($page, ErpPage::class)) {
                $urls[] = $page::getUrl();
            }
        }
        // A label handed to Filament without __() never reaches the translator: find it as English text on the page.
        $id = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/id.json'), true, flags: JSON_THROW_ON_ERROR);
        $english = array_filter($id, fn (string $value, string $key) => $value !== $key && ! in_array($key, self::SAME, true), ARRAY_FILTER_USE_BOTH);
        // Seeded records (the chart of accounts, the "General" category, the "Default" series) are data the company renames.
        foreach (DB::select("select table_name, column_name from information_schema.columns where table_schema = current_schema() and column_name in ('name', 'description') and data_type in ('character varying', 'text')") as $column) {
            foreach (DB::table($column->table_name)->distinct()->pluck($column->column_name) as $stored) {
                unset($english[(string) $stored]);
            }
        }
        $shown = [];
        // A field, entry, column or filter left without ->label() gets one made from its name, never translated
        // (a repeater from its relationship).
        $unlabelled = [];
        $url = '';
        $watch = function (string $kind) use (&$unlabelled, &$url): \Closure {
            return function (object $component) use ($kind, &$unlabelled, &$url): void {
                $component->label(function () use ($component, $kind, &$unlabelled, &$url) {
                    // A hidden label is still read out by screen readers; only a hidden component is not shown at all.
                    if (! (method_exists($component, 'isHidden') && $component->isHidden())) {
                        $unlabelled["{$kind} {$component->getName()}"] ??= parse_url($url, PHP_URL_PATH);
                    }

                    return null;
                });
            };
        };
        $render = function () use ($urls, $english, $watch, &$shown, &$url): void {
            foreach ($urls as $url) {
                $this->freshRequest();
                foreach (['field' => Field::class, 'entry' => Entry::class, 'column' => Column::class, 'filter' => BaseFilter::class] as $kind => $class) {
                    $class::configureUsing($watch($kind)); // on this request's component manager
                }
                $html = $this->get($url)->assertOk()->getContent();
                foreach ($this->visibleTexts((string) $html) as $text) {
                    if (isset($english[$text])) {
                        $shown[$text] ??= parse_url($url, PHP_URL_PATH);
                    }
                }
            }
        };
        $render();
        $this->freshRequest(); // drops the watchers with the request's component manager
        Lang::handleMissingKeysUsing(null);

        $this->assertSame([], array_keys($missing), 'Shown in English on an Indonesian screen:');
        ksort($unlabelled);
        $this->assertSame([], $unlabelled, 'Without a label (Filament makes one from the name, in English):');
        $this->assertSame([], $shown, 'Shown in English on an Indonesian screen (not passed through __()):');
    }

    /** @return list<string> the page's text nodes and placeholders, whitespace collapsed */
    private function visibleTexts(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $texts = [];
        foreach ($xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::template)] | //body//@placeholder') as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '');
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
