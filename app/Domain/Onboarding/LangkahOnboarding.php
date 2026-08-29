<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/**
 * One step of a customer's onboarding, derived — never stored.
 *
 * `selesai` is recomputed from the customer's actual state on every read,
 * so a step cannot stay ticked after it stopped being true: unseat the
 * sales rep and the team step un-ticks itself.
 */
final class LangkahOnboarding
{
    public function __construct(
        public readonly string $kunci,
        public readonly string $judul,
        public readonly bool $selesai,
        public readonly string $temuan,
    ) {}

    public static function selesai(string $kunci, string $judul, string $temuan): self
    {
        return new self($kunci, $judul, true, $temuan);
    }

    public static function belum(string $kunci, string $judul, string $temuan): self
    {
        return new self($kunci, $judul, false, $temuan);
    }
}
