<?php

declare(strict_types=1);

namespace App\Domain\Explorer;

use App\Domain\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One column, defined once and rendered twice.
 *
 * The screen and the download read the same definitions, for the same reason
 * `ReportColumn` exists: a figure formatted one way on screen and another in
 * the CSV is how a spreadsheet built on the export ends up disagreeing with
 * the page it came from. Here it also settles *which* columns exist at all —
 * a column hidden from a role must be missing from their download too, not
 * merely absent from their screen.
 */
final class ExplorerColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const DATE = 'date';

    /**
     * @param  (callable(Model): mixed)|null  $value  null reads the attribute by key
     * @param  string|null  $sortBy  the database column, when it differs
     * @param  (callable(): bool)|null  $visible
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        private $value,
        public readonly ?string $sortBy,
        private $visible,
    ) {}

    public static function text(string $key, string $label, ?callable $value = null, ?string $sortBy = null, ?callable $visible = null): self
    {
        return new self($key, $label, self::TEXT, $value, $sortBy, $visible);
    }

    public static function money(string $key, string $label, ?callable $value = null, ?string $sortBy = null, ?callable $visible = null): self
    {
        return new self($key, $label, self::MONEY, $value, $sortBy, $visible);
    }

    public static function number(string $key, string $label, ?callable $value = null, ?string $sortBy = null, ?callable $visible = null): self
    {
        return new self($key, $label, self::NUMBER, $value, $sortBy, $visible);
    }

    public static function date(string $key, string $label, ?callable $value = null, ?string $sortBy = null, ?callable $visible = null): self
    {
        return new self($key, $label, self::DATE, $value, $sortBy, $visible);
    }

    public function isVisible(): bool
    {
        return $this->visible === null || ($this->visible)();
    }

    /** Whether this column can be sorted in the database rather than in PHP. */
    public function sortable(): bool
    {
        return $this->sortBy !== null || ! str_contains($this->key, '.');
    }

    public function state(Model $record): mixed
    {
        if ($this->value !== null) {
            return ($this->value)($record);
        }

        return data_get($record, $this->key);
    }

    /** For the screen. */
    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($this->type) {
            self::MONEY => Money::format((int) $value),
            self::NUMBER => number_format((float) $value, 0, ',', '.'),
            self::DATE => $this->toDate($value)?->format('d/m/Y') ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * For the CSV.
     *
     * Money and counts go out bare so a spreadsheet can add them up, and
     * dates go out ISO so it sorts them as dates — the same split
     * `ReportColumn` makes, for the same reasons.
     */
    public function forCsv(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($this->type) {
            self::MONEY, self::NUMBER => (string) $value,
            self::DATE => $this->toDate($value)?->format('Y-m-d') ?? (string) $value,
            default => (string) $value,
        };
    }

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
