<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Launch;

/**
 * One item of launch readiness: checked by the system (evidence, recomputed
 * every time) or attested by a person (their word, with their name, the
 * date and a note). The two are kept visibly apart.
 */
final readonly class LaunchCheck
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public bool $automatic,
        public bool $passed,
        public ?string $finding = null,
        public ?string $action = null,
        public ?string $attestedBy = null,
        public ?string $attestedAt = null,
        public ?string $note = null,
    ) {}

    public static function checked(string $key, string $title, string $description, bool $passed, ?string $finding = null, ?string $action = null): self
    {
        return new self($key, $title, $description, true, $passed, $finding, $action);
    }
}
