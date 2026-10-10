<?php

declare(strict_types=1);

namespace App\Client\Domain\Books;

/** One item of the year-end checklist. */
final class YearEndCheck
{
    public function __construct(public readonly string $key, public readonly string $title, public readonly bool $passed, public readonly ?string $finding = null) {}
}
