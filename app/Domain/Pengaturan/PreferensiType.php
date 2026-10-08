<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

/** How a preference value is typed when read back from its JSON column. */
enum PreferensiType: string
{
    case Bool = 'bool';
    case Text = 'text';
    case Int = 'int';
    case Date = 'date';
    case Time = 'time';
    case Select = 'select';
    case Account = 'account';
    case TextList = 'text_list';

    public function cast(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($this) {
            self::Bool => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            self::Int, self::Account => (int) $raw,
            self::Text, self::Date, self::Time, self::Select => (string) $raw,
            self::TextList => array_values(array_map(fn ($v) => $v === null ? null : (string) $v, (array) $raw)),
        };
    }
}
