<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * An ordered list of tokens, stored as JSON on the series. "SO-YYMM-####" is
 * [text "SO-", short year, month, text "-", counter]. A pattern must hold
 * exactly one counter.
 */
final class NumberPattern
{
    /** @param  list<array{token: string, text?: string|null}>  $parts */
    public function __construct(public readonly array $parts)
    {
        $counters = count(array_filter($parts, fn (array $p) => $p['token'] === PatternToken::Counter->value));
        if ($counters !== 1) {
            throw new InvalidArgumentException(__('A number format needs exactly one counter.'));
        }
    }

    /** @param  list<array{token: string, text?: string|null}>  $parts */
    public static function fromArray(array $parts): self
    {
        return new self(array_values(array_map(fn (array $p) => ['token' => $p['token'], 'text' => $p['text'] ?? null], $parts)));
    }

    /**
     * Parses the short notation DESIGN.md uses: YYYY, YY, MM, RM (Roman month),
     * DD, a run of # for the counter; anything else is separator text.
     */
    public static function fromFormat(string $format): self
    {
        preg_match_all('/YYYY|YY|MM|RM|DD|#+|[^#YMRD]+|./', $format, $matches);
        $parts = [];
        foreach ($matches[0] as $piece) {
            $parts[] = match (true) {
                $piece === 'YYYY' => ['token' => PatternToken::Year->value, 'text' => null],
                $piece === 'YY' => ['token' => PatternToken::ShortYear->value, 'text' => null],
                $piece === 'MM' => ['token' => PatternToken::Month->value, 'text' => null],
                $piece === 'RM' => ['token' => PatternToken::RomanMonth->value, 'text' => null],
                $piece === 'DD' => ['token' => PatternToken::Day->value, 'text' => null],
                $piece[0] === '#' => ['token' => PatternToken::Counter->value, 'text' => null],
                default => ['token' => PatternToken::Text->value, 'text' => $piece],
            };
        }

        return new self(self::mergeText($parts));
    }

    public function render(CarbonInterface $date, int $counter, int $digits): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            $out .= PatternToken::from($part['token'])->render($date, $counter, $digits, $part['text'] ?? null);
        }

        return $out;
    }

    /** @return list<array{token: string, text: string|null}> */
    public function toArray(): array
    {
        return $this->parts;
    }

    /** @param  list<array{token: string, text: string|null}>  $parts */
    private static function mergeText(array $parts): array
    {
        $merged = [];
        foreach ($parts as $part) {
            $last = array_key_last($merged);
            if ($last !== null && $part['token'] === 'text' && $merged[$last]['token'] === 'text') {
                $merged[$last]['text'] .= $part['text'];

                continue;
            }
            $merged[] = $part;
        }

        return $merged;
    }
}
