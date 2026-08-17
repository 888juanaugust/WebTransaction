<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money;

/**
 * One column, and how to render a cell of it.
 *
 * The formatter lives here rather than in the Blade so that the screen and the
 * CSV cannot drift apart — a margin percentage shown as `18,4%` on screen and
 * `0.18432` in the download is the kind of difference somebody only notices
 * after building a spreadsheet on top of it.
 *
 * `visible` is a closure rather than a boolean because the same report is used
 * by roles that may not see the same columns: Sales look at what they sold and
 * must not see what it cost, since cost plus selling price is margin.
 */
final class ReportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    /**
     * A number with one decimal place and no unit attached.
     *
     * Distinct from PERCENT, which appends a `%`. Months of cover is a
     * decimal and is not a percentage — rendering it through the percent
     * formatter printed "24,3%" for what is two years of stock, which is not
     * a rounding error but a different quantity entirely.
     */
    public const DECIMAL = 'decimal';

    public const DATE = 'date';

    /**
     * @param  (callable(): bool)|null  $visible
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        private $visible,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, self::TEXT, null);
    }

    public static function money(string $key, string $label, ?callable $visible = null): self
    {
        return new self($key, $label, self::MONEY, $visible);
    }

    public static function number(string $key, string $label, ?callable $visible = null): self
    {
        return new self($key, $label, self::NUMBER, $visible);
    }

    public static function percent(string $key, string $label, ?callable $visible = null): self
    {
        return new self($key, $label, self::PERCENT, $visible);
    }

    public static function decimal(string $key, string $label, ?callable $visible = null): self
    {
        return new self($key, $label, self::DECIMAL, $visible);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, self::DATE, null);
    }

    public function isVisible(): bool
    {
        return $this->visible === null || ($this->visible)();
    }

    public function alignsRight(): bool
    {
        return in_array($this->type, [self::MONEY, self::NUMBER, self::PERCENT, self::DECIMAL], true);
    }

    /** For the screen. */
    public function format(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($this->type) {
            self::MONEY => Money::format((int) $value),
            self::NUMBER => number_format((float) $value, 0, ',', '.'),
            // One decimal place. Margin percentages are read to decide whether
            // a customer is worth keeping, and the second decimal never
            // changed anybody's mind.
            self::PERCENT => number_format((float) $value, 1, ',', '.').'%',
            self::DECIMAL => number_format((float) $value, 1, ',', '.'),
            self::DATE => $this->toDate($value)?->format('d/m/Y') ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * For the CSV.
     *
     * Money and quantities go out as bare integers, not formatted — a
     * spreadsheet has to be able to add them up, and `Rp 1.250.000` is text.
     * That is the whole reason this is a separate method rather than reusing
     * the one above.
     */
    public function forCsv(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($this->type) {
            self::MONEY, self::NUMBER => (string) $value,
            self::PERCENT, self::DECIMAL => number_format((float) $value, 2, '.', ''),
            // ISO in the download, deliberately unlike the screen: a
            // spreadsheet sorts `2026-08-17` as a date and `17/08/2026` as
            // text, and the whole point of the CSV is to be sorted.
            self::DATE => $this->toDate($value)?->format('Y-m-d') ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * A date, whatever shape the report handed over.
     *
     * Reports build their rows from raw query results, so a date arrives
     * sometimes as a Carbon and sometimes as the `Y-m-d` string a
     * `toDateString()` left behind. Without this the two rendered differently
     * on the same screen — `17/08/2026` in one column and `2026-08-17` in the
     * next — which reads as a bug even though both are the right day.
     */
    private function toDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
