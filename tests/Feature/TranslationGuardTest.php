<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every English string the UI shows passes through __(), so a lang/<locale>.json
 * translates it without a code change. Run `node tools/i18n/wrap-literals.mjs`
 * to wrap what this test lists; the two apply the same rules.
 */
class TranslationGuardTest extends TestCase
{
    private const METHODS = ['label', 'placeholder', 'helperText', 'hint', 'title', 'heading', 'description', 'modalHeading', 'modalDescription', 'modalSubmitActionLabel', 'modalCancelActionLabel', 'emptyStateHeading', 'emptyStateDescription', 'body', 'tooltip', 'successNotificationTitle', 'navigationLabel', 'addActionLabel'];

    private const MAKES = ['Tab', 'Section', 'Fieldset', 'Stat', 'TableColumn', 'Step'];

    private const LABEL_METHODS = ['label', 'getLabel', 'help', 'options', 'description', 'title', 'heading', 'statuses'];

    /** Column helpers whose second argument is the label: static::money('total', 'Total'). */
    private const LABEL_HELPERS = '(?:static|self|PricedDocumentForm|SettlementLineFields)::(?:money|text|date|quantity|amount)';

    private const SQ = "'(?:[^'\\\\]|\\\\.)*'";

    public function test_no_inline_ui_string_escapes_the_translator(): void
    {
        $offences = [];
        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
            $src = $file->getContents();
            $rel = $file->getRelativePathname();
            $methods = implode('|', self::METHODS);
            foreach ($this->findAll($src, "/->(?:{$methods})\\(\\s*(".self::SQ.')\\s*\\)/') as [$lit, $line]) {
                if (self::isText(self::inner($lit))) {
                    $offences[] = "{$rel}:{$line} {$lit}";
                }
            }
            foreach ($this->findAll($src, "/->(?:{$methods})\\(\\s*(\"(?:[^\"\\\\]|\\\\.)*\")\\s*\\)/") as [$lit, $line]) {
                if (self::isText(self::inner($lit))) {
                    $offences[] = "{$rel}:{$line} {$lit}";
                }
            }
            $makes = implode('|', self::MAKES);
            foreach ($this->findAll($src, "/(?:{$makes})::make\\(\\s*(".self::SQ.')/') as [$lit, $line]) {
                if (self::isLabelish(self::inner($lit))) {
                    $offences[] = "{$rel}:{$line} {$lit}";
                }
            }
            // Messages the user reads: exceptions shown in notifications, validation failures.
            foreach (['new\\s+\\\\?RuntimeException\\(\\s*', '\\$fail\\(\\s*'] as $opener) {
                foreach ($this->findAll($src, "/{$opener}(".self::SQ.'|"(?:[^"\\\\]|\\\\.)*")/') as [$lit, $line]) {
                    if (self::isText(self::inner($lit))) {
                        $offences[] = "{$rel}:{$line} {$lit}";
                    }
                }
            }
            foreach ($this->findAll($src, '/'.self::LABEL_HELPERS.'\\(\\s*'.self::SQ.'\\s*,\\s*('.self::SQ.')/') as [$lit, $line]) {
                if (self::isText(self::inner($lit))) {
                    $offences[] = "{$rel}:{$line} {$lit}";
                }
            }
            foreach ($this->blocks($src, '/\\bfunction\\s+exportHeaders\\s*\\(/', '(', ')', true) as [$block, $offset]) {
                foreach ($this->findAll($block, '/[\\[,]\\s*('.self::SQ.')(?=\\s*[,\\]])/') as [$lit, $line, $pos]) {
                    if (self::isText(self::inner($lit))) {
                        $offences[] = "{$rel}:".(substr_count(substr($src, 0, $offset + $pos), "\n") + 1)." {$lit}";
                    }
                }
            }
            // A stored code is shown through Format::code() (lang/<locale>/status.php), never made readable by hand.
            if ($rel !== 'Domain/Shared/Format.php') {
                foreach ($this->findAll($src, '/\\b(ucfirst)\\(/') as [$lit, $line]) {
                    $offences[] = "{$rel}:{$line} ucfirst(): use Format::code()";
                }
            }
            $blocks = [];
            if (str_starts_with($rel, 'Filament/')) {
                $blocks = array_merge($blocks, $this->blocks($src, '/->options\\(\\s*\\[/', '[', ']'), $this->blocks($src, '/\\bmatch\\s*\\(/', '(', ')', true));
            }
            $labelMethods = implode('|', self::LABEL_METHODS);
            $blocks = array_merge($blocks, $this->blocks($src, "/\\bfunction\\s+(?:{$labelMethods})\\s*\\(/", '(', ')', true));
            foreach ($blocks as [$block, $offset]) {
                foreach ($this->findAll($block, '/(?:=>\\s*|\\breturn\\s+)('.self::SQ.')/') as [$lit, $line, $pos]) {
                    if (self::isLabelish(self::inner($lit))) {
                        $offences[] = "{$rel}:".(substr_count(substr($src, 0, $offset + $pos), "\n") + 1)." {$lit}";
                    }
                }
            }
        }
        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php') as $file) {
            $rel = 'resources/views/'.$file->getRelativePathname();
            foreach ($this->findAll($file->getContents(), '/>[ \\t]*\\n?[ \\t]*([A-Z][^<>{}@\\n]*?)[ \\t]*\\n?[ \\t]*</') as [$text, $line]) {
                if (self::isText(trim($text))) {
                    $offences[] = "{$rel}:{$line} >{$text}<";
                }
            }
            foreach ($this->findAll($file->getContents(), '/\\splaceholder="([A-Z][^"{}@]*)"/') as [$text, $line]) {
                $offences[] = "{$rel}:{$line} placeholder=\"{$text}\"";
            }
        }

        $this->assertSame([], $offences, "Inline UI strings not wrapped in __(); run node tools/i18n/wrap-literals.mjs:\n".implode("\n", $offences));
    }

    private static function inner(string $lit): string
    {
        return substr($lit, 1, -1);
    }

    private static function isText(string $s): bool
    {
        return preg_match('/[A-Za-z]/', $s) === 1 && ! str_starts_with($s, 'heroicon') && ! str_starts_with($s, 'App\\') && preg_match('/^[a-z-]+:\s*$/', $s) !== 1;
    }

    private static function isLabelish(string $s): bool
    {
        return self::isText($s) && preg_match('/[A-Z ]/', $s) === 1;
    }

    /** @return list<array{0: string, 1: int, 2: int}> capture, line, offset of each match */
    private function findAll(string $src, string $pattern): array
    {
        preg_match_all($pattern, $src, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $out = [];
        foreach ($all as $m) {
            $out[] = [$m[1][0], substr_count(substr($src, 0, $m[1][1]), "\n") + 1, $m[1][1]];
        }

        return $out;
    }

    /** @return list<array{0: string, 1: int}> each bracketed block after an opener, with its offset */
    private function blocks(string $src, string $opener, string $open, string $close, bool $thenBraces = false): array
    {
        preg_match_all($opener, $src, $all, PREG_OFFSET_CAPTURE);
        $blocks = [];
        foreach ($all[0] as [$text, $offset]) {
            $start = strpos($src, $open, $offset + strlen($text) - 1);
            if ($start === false) {
                continue;
            }
            $end = $this->matchBracket($src, $start, $open, $close);
            if ($end < 0) {
                continue;
            }
            if ($thenBraces) {
                $brace = strpos($src, '{', $end);
                if ($brace === false) {
                    continue;
                }
                $between = trim(substr($src, $end + 1, $brace - $end - 1));
                if ($between !== '' && preg_match('/^:\s*\??[\w\\\\|]+$/', $between) !== 1) {
                    continue;
                }
                $start = $brace;
                $end = $this->matchBracket($src, $brace, '{', '}');
                if ($end < 0) {
                    continue;
                }
            }
            $blocks[] = [substr($src, $start, $end - $start + 1), $start];
        }

        return $blocks;
    }

    private function matchBracket(string $src, int $start, string $open, string $close): int
    {
        $depth = 0;
        $quote = null;
        for ($i = $start, $n = strlen($src); $i < $n; $i++) {
            $c = $src[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '/' && ($src[$i + 1] ?? '') === '/') {
                $i = strpos($src, "\n", $i) ?: $n;
            } elseif ($c === $open) {
                $depth++;
            } elseif ($c === $close && --$depth === 0) {
                return $i;
            }
        }

        return -1;
    }
}
