<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Health;

/** One health check's answer: what it looked at, how it stands, what it found. */
final readonly class OpsCheck
{
    public function __construct(public string $key, public string $title, public OpsStatus $status, public string $finding) {}

    public static function healthy(string $key, string $title, string $finding): self
    {
        return new self($key, $title, OpsStatus::Healthy, $finding);
    }

    public static function warning(string $key, string $title, string $finding): self
    {
        return new self($key, $title, OpsStatus::Warning, $finding);
    }

    public static function critical(string $key, string $title, string $finding): self
    {
        return new self($key, $title, OpsStatus::Critical, $finding);
    }
}
